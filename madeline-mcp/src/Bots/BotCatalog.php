<?php

declare(strict_types=1);

namespace MadelineMcp\Bots;

use MadelineMcp\ApiClient;
use Throwable;

/**
 * Drive ANY Telegram bot through the user account: scan its UI primitives
 * (commands, keyboards, inline buttons) into an interaction map, then invoke
 * actions against it. Generic tools -> no per-bot codegen, no tool churn.
 */
final class BotCatalog
{
    private const SCAN_TTL = 6 * 3600;

    public function has(string $tool): bool
    {
        return \in_array($tool, ['bots.list', 'bot.scan', 'bot.invoke'], true);
    }

    /** @return list<array<string,mixed>> */
    public function tools(): array
    {
        return [
            [
                'name' => 'bots.list',
                'description' => 'List bots present in this account\'s dialogs, with cached interaction-map freshness.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'bot.scan',
                'description' => 'Analyse a bot conversation and build its interaction map (commands, reply keyboard rows, inline/callback buttons). Cached ~6h; force=true re-scans.',
                'inputSchema' => ['type' => 'object', 'required' => ['peer'], 'properties' => [
                    'peer' => ['type' => 'string', 'description' => 'Bot username (@x) or id.'],
                    'force' => ['type' => 'boolean', 'description' => 'Re-scan now instead of using cache.'],
                    'session_name' => ['type' => 'string'],
                ]],
            ],
            [
                'name' => 'bot.invoke',
                'description' => 'Invoke an action on a bot: a command (/cmd args), a reply-keyboard button text, or an inline button text (pressed via callback). Waits for the bot reply and returns it with the next buttons so you can chain multi-step flows.',
                'inputSchema' => ['type' => 'object', 'required' => ['peer', 'action'], 'properties' => [
                    'peer' => ['type' => 'string', 'description' => 'Bot username (@x) or id.'],
                    'action' => ['type' => 'string', 'description' => '/command, or exact button text from bot.scan map.'],
                    'args' => ['type' => 'string', 'description' => 'Optional text appended after a /command.'],
                    'wait_seconds' => ['type' => 'integer', 'description' => 'How long to wait for the bot reply (default 6, max 30).'],
                    'session_name' => ['type' => 'string'],
                ]],
            ],
        ];
    }

    public function dispatch(string $tool, array $args, ApiClient $client): mixed
    {
        try {
            return match ($tool) {
                'bots.list' => $this->listBots($client),
                'bot.scan' => $this->scan($args, $client),
                'bot.invoke' => $this->invoke($args, $client),
                default => ['_error' => true, 'message' => "Unknown bots tool: $tool"],
            };
        } catch (Throwable $e) {
            return ['_error' => true, 'code' => $e->getCode(), 'message' => $e->getMessage(), 'class' => \get_class($e)];
        }
    }

    private function sessionOf(array $args, ApiClient $client): string
    {
        $s = $args['session_name'] ?? null;
        return (\is_string($s) && $s !== '') ? $s : $client->defaultSession();
    }

    private function cacheFile(string $session, string $botKey): string
    {
        return ApiClient::cacheDir() . '/bots/' . \preg_replace('/[^a-z0-9_\-]/i', '_', $session . '-' . \ltrim($botKey, '@')) . '.json';
    }

    private function listBots(ApiClient $client): array
    {
        $api = $client->api();
        $dialogs = $api->messages->getDialogs(limit: 100);
        $out = []; // bots appear as users in dialogs
        foreach ((array) ($dialogs['users'] ?? []) as $u) {
            if (!\is_array($u) || empty($u['bot'])) {
                continue;
            }
            $uname = (string) ($u['username'] ?? '');
            $key = $uname !== '' ? '@' . $uname : (string) ($u['id'] ?? '');
            $file = $this->cacheFile($this->sessionOf([], $client), $key);
            $out[] = [
                'peer' => $key,
                'first_name' => (string) ($u['first_name'] ?? ''),
                'verified_scan' => \is_file($file) ? (bool) (($this->readJson($file)['scanned_at'] ?? 0)) : false,
            ];
        }
        return ['bots' => $out];
    }

    private function scan(array $args, ApiClient $client): array
    {
        $peer = (string) ($args['peer'] ?? '');
        if ($peer === '') {
            return ['_error' => true, 'message' => 'peer required'];
        }
        $session = $this->sessionOf($args, $client);
        $key = $this->botKey($client, $session, $peer);
        $file = $this->cacheFile($session, $key);

        if (!($args['force'] ?? false) && \is_file($file)) {
            $cached = $this->readJson($file);
            if ($cached !== null && (\time() - (int) ($cached['scanned_at'] ?? 0)) < self::SCAN_TTL) {
                return \array_merge($cached, ['cached' => true]);
            }
        }

        $api = $client->api($session);
        $history = $api->messages->getHistory(peer: $peer, limit: 40, offset_id: 0);

        $username = '';
        $title = '';
        try {
            $info = $api->getInfo($peer);
            if (\is_array($info)) {
                $u = (array) ($info['User'] ?? []);
                $username = (string) ($u['username'] ?? '');
                $title = \trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
                if ((bool) ($u['bot'] ?? false) === false) {
                    return ['_error' => true, 'message' => "Peer '$peer' is not a bot."];
                }
            }
        } catch (Throwable) {
        }
        $about = '';
        try {
            $full = $api->getFullInfo($peer);
            $fu = (array) (((array) ($full ?? []))['full_user'] ?? []);
            $about = (string) ($fu['about'] ?? '');
        } catch (Throwable) {
        }

        $map = BotScanner::fromHistory((array) $history, $username, $title, $about);
        $map['cached'] = false;
        @\mkdir(\dirname($file), 0755, true);
        @\file_put_contents($file, \json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $map;
    }

    private function invoke(array $args, ApiClient $client): array
    {
        $peer = (string) ($args['peer'] ?? '');
        $action = (string) ($args['action'] ?? '');
        if ($peer === '' || $action === '') {
            return ['_error' => true, 'message' => 'peer and action required'];
        }
        $session = $this->sessionOf($args, $client);

        // Ensure a fresh-enough map exists for callback presses.
        $key = $this->botKey($client, $session, $peer);
        $file = $this->cacheFile($session, $key);
        $map = $this->readJson($file) ?? [];
        if ($map === [] || (\time() - (int) ($map['scanned_at'] ?? 0)) > self::SCAN_TTL) {
            $scanRes = $this->scan(['peer' => $peer, 'force' => true, 'session_name' => $session], $client);
            if (\is_array($scanRes) && !isset($scanRes['_error'])) {
                $map = $scanRes;
            }
        }

        if (\str_starts_with($action, '/') && isset($args['args']) && \is_string($args['args']) && $args['args'] !== '') {
            $action = \rtrim($action, '/') . ' ' . $args['args'];
        }

        $api = $client->api($session);
        $wait = \min(30, \max(1, (int) ($args['wait_seconds'] ?? 6)));
        return BotInvoker::invoke($api, $peer, $action, $map, $wait);
    }

    /** Stable cache key for a peer (prefer resolved username). */
    private function botKey(ApiClient $client, string $session, string $peer): string
    {
        $p = \ltrim(\trim($peer), '@');
        if (\preg_match('/^-?\d+$/', $p) === 1) {
            try {
                $info = $client->api($session)->getInfo($peer);
                $u = (array) ((array) $info['User'] ?? []);
                $un = (string) ($u['username'] ?? '');
                if ($un !== '') {
                    return '@' . $un;
                }
            } catch (Throwable) {
            }
        }
        return $p;
    }

    private function readJson(string $file): ?array
    {
        $raw = @\file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $d = \json_decode($raw, true);
        return \is_array($d) ? $d : null;
    }
}
