<?php

declare(strict_types=1);

namespace MadelineMcp\Limits;

use MadelineMcp\ApiClient;
use Throwable;

/**
 * Community Telegram limits (limits.tginfo.me / tginfo/Telegram-Limits).
 *
 * Fetch strategy (auto-updating, resilient):
 *   1. fresh cache file (age < TTL)
 *   2. GitHub raw structure.json + localization/<lang>/data.json
 *   3. stale cache
 *   4. bundled snapshot in resources/limits-snapshot-<lang>.json
 */
final class LimitsRepository
{
    private const TTL = 24 * 3600;
    private const RAW_STRUCTURE = 'https://raw.githubusercontent.com/tginfo/Telegram-Limits/master/data/structure.json';
    private const RAW_LOCALIZATION = 'https://raw.githubusercontent.com/tginfo/Telegram-Limits/master/localization/%s/data.json';

    private string $lang;

    public function __construct(string $lang = 'en')
    {
        $this->lang = \preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $lang) === 1 ? $lang : 'en';
    }

    private function cacheFile(): string
    {
        return ApiClient::cacheDir() . '/telegram-limits-' . $this->lang . '.json';
    }

    private function bundledFile(): string
    {
        return \dirname(__DIR__, 2) . '/resources/limits-snapshot-' . $this->lang . '.json';
    }

    /**
     * @return array{meta:array<string,mixed>, categories:list<array<string,mixed>>}
     */
    public function snapshot(bool $refresh = false): array
    {
        if (!$refresh) {
            $fresh = $this->readCache(false);
            if ($fresh !== null && (\time() - (int) ($fresh['meta']['fetched_at'] ?? 0)) < self::TTL) {
                return $fresh;
            }
        }

        $fetched = $this->fetchRemote();
        if ($fetched !== null) {
            @\file_put_contents($this->cacheFile(), \json_encode($fetched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $fetched;
        }

        $stale = $this->readCache(true);
        if ($stale !== null) {
            return $stale;
        }
        return $this->readBundled();
    }

    /** Filtered view for the LLM: one category or the whole tree, compact. */
    public function forTool(bool $refresh = false, ?string $category = null): array
    {
        $snap = $this->snapshot($refresh);
        $cats = $snap['categories'];
        if ($category !== null) {
            $cats = \array_values(\array_filter($cats, static fn ($c) => $c['id'] === $category));
        }
        return ['meta' => $snap['meta'], 'categories' => $cats];
    }

    /**
     * Numeric budget applicable to an account type for a tracked limit id.
     * Parses "up to N" from community text; premium value wins when present.
     * @return array{limit:int|null,text:string,premium:bool}
     */
    public function numericLimit(string $limitId, bool $premium): array
    {
        // Accept both '<category>.<item>' and a bare '<item>' id.
        $parts = \explode('.', $limitId);
        $itemWanted = (string) \end($parts);
        $catWanted = \count($parts) === 2 ? $parts[0] : null;
        foreach ($this->snapshot()['categories'] as $cat) {
            if ($catWanted !== null && ($cat['id'] ?? null) !== $catWanted) {
                continue;
            }
            foreach ($cat['items'] as $item) {
                if (($item['id'] ?? null) === $itemWanted) {
                    $text = (string) ($item['text'] ?? '');
                    $prem = $item['premium'] ?? null;
                    $chosen = ($premium && \is_string($prem) && $prem !== '') ? $prem : $text;
                    if (\preg_match('/([\d][\d,_\s]*)/', $chosen, $m) === 1) {
                        $num = (int) \str_replace([',', '_', ' '], '', $m[1]);
                        return ['limit' => $num, 'text' => $text, 'premium' => $premium && \is_string($prem) && $prem !== ''];
                    }
                    return ['limit' => null, 'text' => $text, 'premium' => false];
                }
            }
        }
        return ['limit' => null, 'text' => '', 'premium' => false];
    }

    /** @return array<string,mixed>|null */
    private function readCache(bool $markStale): ?array
    {
        if (!\is_file($this->cacheFile())) {
            return null;
        }
        try {
            /** @var array<string,mixed> $d */
            $d = \json_decode((string) \file_get_contents($this->cacheFile()), true, 512, JSON_THROW_ON_ERROR);
            if (\is_array($d) && isset($d['categories'])) {
                if ($markStale) {
                    $d['meta']['stale'] = true;
                }
                return $d;
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function readBundled(): array
    {
        try {
            /** @var array<string,mixed> $d */
            $d = \json_decode((string) \file_get_contents($this->bundledFile()), true, 512, JSON_THROW_ON_ERROR);
            $d['meta']['stale'] = true;
            $d['meta']['source'] = 'bundled snapshot (' . $d['meta']['source'] . ')';
            return $d;
        } catch (Throwable) {
            return [
                'meta' => ['source' => 'unavailable', 'lang' => $this->lang, 'fetched_at' => 0, 'stale' => true],
                'categories' => [],
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchRemote(): ?array
    {
        try {
            $ctx = \stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: madeline-mcp\r\n"]]);
            $structureRaw = @\file_get_contents(self::RAW_STRUCTURE, false, $ctx);
            $localRaw = @\file_get_contents(\sprintf(self::RAW_LOCALIZATION, $this->lang), false, $ctx);
            if ($structureRaw === false || $localRaw === false) {
                return null;
            }
            $structure = \json_decode($structureRaw, true, 512, JSON_THROW_ON_ERROR);
            $local = \json_decode($localRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($structure) || !\is_array($local)) {
                return null;
            }

            $categories = [];
            foreach ($structure as $cat) {
                $cid = (string) ($cat['id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $items = [];
                foreach (((array) ($local[$cid]['items'] ?? [])) as $iid => $it) {
                    $items[] = [
                        'id' => (string) $iid,
                        'name' => (string) ($it['name'] ?? ''),
                        'hint' => (string) ($it['hint'] ?? ''),
                        'text' => (string) ($it['text'] ?? ''),
                        'premium' => isset($it['text_premium']) ? (string) $it['text_premium'] : null,
                    ];
                }
                $categories[] = [
                    'id' => $cid,
                    'name' => (string) ($local[$cid]['name'] ?? $cid),
                    'items' => $items,
                ];
            }

            return [
                'meta' => [
                    'source' => 'github:tginfo/Telegram-Limits',
                    'url' => 'https://limits.tginfo.me/' . $this->lang,
                    'lang' => $this->lang,
                    'fetched_at' => \time(),
                    'ttl_hours' => (int) (self::TTL / 3600),
                ],
                'categories' => $categories,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
