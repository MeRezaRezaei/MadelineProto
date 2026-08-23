<?php

declare(strict_types=1);

namespace MadelineMcp\Limits;

use MadelineMcp\ApiClient;
use Throwable;

/**
 * Per-account budgeting state: counters, FLOOD_WAIT history and cooldown
 * locks. Persisted under cache/usage-<session>.json so restarts keep memory.
 */
final class UsageTracker
{
    private const RING_CATEGORIES = ['message']; // rate-windowed categories

    public function __construct(private readonly string $session)
    {
    }

    public static function forSession(string $session): self
    {
        return new self($session);
    }

    private function file(): string
    {
        return ApiClient::cacheDir() . '/usage-' . $this->session . '.json';
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if (\is_file($this->file())) {
            try {
                /** @var array<string,mixed> $d */
                $d = \json_decode((string) \file_get_contents($this->file()), true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($d)) {
                    return $d;
                }
            } catch (Throwable) {
                // fall through to fresh state
            }
        }
        return ['counters' => [], 'rings' => [], 'cooldowns' => [], 'flood_waits' => []];
    }

    private function save(array $state): void
    {
        $state['updated_at'] = \time();
        @\file_put_contents($this->file(), \json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    /** Count one call in a category. */
    public function record(string $category, int $weight = 1): void
    {
        $state = $this->load();
        $day = \date('Y-m-d');
        $state['counters'][$category][$day] = ($state['counters'][$category][$day] ?? 0) + $weight;
        // prune older days
        foreach ($state['counters'] as $cat => $days) {
            $state['counters'][$cat] = \array_filter(
                $days,
                static fn ($k) => \strtotime((string) $k) >= \strtotime('-2 day'),
                ARRAY_FILTER_USE_KEY
            );
        }
        if (\in_array($category, self::RING_CATEGORIES, true)) {
            $ring = $state['rings'][$category] ?? [];
            $ring[] = \time();
            $state['rings'][$category] = \array_values(\array_filter($ring, static fn ($t) => $t >= \time() - 3600));
        }
        $this->save($state);
    }

    /** Calls in a category within the last $window seconds. */
    public function rate(string $category, int $window = 60): int
    {
        $state = $this->load();
        $min = \time() - $window;
        return \count(\array_filter($state['rings'][$category] ?? [], static fn ($t) => $t >= $min));
    }

    /** Used-today counter for a category. */
    public function usedToday(string $category): int
    {
        $state = $this->load();
        return (int) ($state['counters'][$category][\date('Y-m-d')] ?? 0);
    }

    /** Record a FLOOD_WAIT; sets cooldown locks. Returns seconds waited. */
    public function recordFloodWait(int $seconds, string $method, ?string $category = null): void
    {
        $state = $this->load();
        $until = \time() + \max(1, $seconds);
        // global lock plus per-category lock
        $state['cooldowns']['*'] = \max($state['cooldowns']['*'] ?? 0, $until);
        if ($category !== null && $category !== '*') {
            $state['cooldowns'][$category] = \max($state['cooldowns'][$category] ?? 0, $until);
        }
        $waits = $state['flood_waits'] ?? [];
        $waits[] = ['at' => \time(), 'method' => $method, 'seconds' => $seconds];
        $state['flood_waits'] = \array_slice($waits, -50);
        $this->save($state);
    }

    /** Extract FLOOD_WAIT seconds from any thrown error. */
    public static function floodSeconds(Throwable $e): ?int
    {
        if (\preg_match('/FLOOD(?:_PREMIUM)?_WAIT_(\d+)/', $e->getMessage(), $m) === 1) {
            return (int) $m[1];
        }
        if (\preg_match('/FLOOD(?:_PREMIUM)?_WAIT_(\d+)/', \get_class($e), $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /** @return array{category:string, until:int, remaining:int, scope:string}|null */
    public function blocked(?string $category): ?array
    {
        $state = $this->load();
        $now = \time();
        foreach ([$category, '*'] as $scope) {
            if ($scope === null) {
                continue;
            }
            $until = (int) ($state['cooldowns'][$scope] ?? 0);
            if ($until > $now) {
                return [
                    'category' => $category ?? 'global',
                    'until' => $until,
                    'remaining' => $until - $now,
                    'scope' => $scope === '*' ? 'global' : $scope,
                ];
            }
        }
        return null;
    }

    /** Clear one or all cooldown locks (admin escape hatch). */
    public function clearCooldowns(?string $category = null): void
    {
        $state = $this->load();
        $state['cooldowns'] = $category === null ? [] : \array_diff_key($state['cooldowns'], [$category => 1]);
        $this->save($state);
    }

    /** @return array<string,int> active cooldowns */
    public function cooldowns(): array
    {
        $state = $this->load();
        $now = \time();
        return \array_filter(
            $state['cooldowns'] ?? [],
            static fn ($until) => (int) $until > $now
        );
    }

    /** @return list<array{at:int,method:string,seconds:int}> */
    public function floodWaits(): array
    {
        return \array_values($this->load()['flood_waits'] ?? []);
    }

    /** Full view used by session.get_quota. */
    public function snapshot(): array
    {
        $state = $this->load();
        return [
            'counters_today' => \array_map(
                static fn ($days) => (int) ($days[\date('Y-m-d')] ?? 0),
                $state['counters'] ?? []
            ),
            'message_rate_last_minute' => $this->rate('message', 60),
            'message_rate_last_hour' => $this->rate('message', 3600),
            'cooldowns' => $this->cooldowns(),
            'flood_waits_recent' => \array_slice($this->floodWaits(), -10),
        ];
    }
}
