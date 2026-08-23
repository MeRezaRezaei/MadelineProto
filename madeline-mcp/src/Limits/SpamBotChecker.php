<?php

declare(strict_types=1);

namespace MadelineMcp\Limits;

use danog\MadelineProto\API;
use MadelineMcp\ApiClient;
use Throwable;

/**
 * Probes @SpamBot to learn the account's standing: clean, temporarily
 * limited (until date) or banned. Result cached ~1h per session.
 */
final class SpamBotChecker
{
    private const TTL = 3600;

    public static function cached(string $session): ?array
    {
        $file = ApiClient::cacheDir() . '/spambot-' . $session . '.json';
        if (!\is_file($file)) {
            return null;
        }
        try {
            /** @var array<string,mixed> $d */
            $d = \json_decode((string) \file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            return \is_array($d) && (\time() - (int) ($d['checked_at'] ?? 0)) < self::TTL ? $d : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{status:string, until:int|null, raw:string, checked_at:int, cached:bool}
     */
    public static function check(ApiClient $client, string $session, bool $force = false): array
    {
        if (!$force) {
            $hit = self::cached($session);
            if ($hit !== null) {
                $hit['cached'] = true;
                return $hit;
            }
        }

        try {
            $api = $client->api($session);

            // Top incoming message id before we ping the bot.
            $prev = null;
            try {
                $msg = self::topIncoming($api->messages->getHistory(peer: 'SpamBot', limit: 1, offset_id: 0));
                $prev = $msg['id'] ?? null;
            } catch (Throwable) {
                $prev = null;
            }

            // Ask the bot for a fresh verdict.
            try {
                $api->messages->sendMessage(peer: 'SpamBot', message: '/start');
            } catch (Throwable $e) {
                // PEER_FLOOD etc. — still try to read whatever the bot last said.
            }

            // Poll briefly for a NEW incoming reply (id > prev).
            $reply = null;
            for ($i = 0; $i < 4; $i++) {
                \sleep(1);
                try {
                    $cand = self::topIncoming($api->messages->getHistory(peer: 'SpamBot', limit: 3, offset_id: 0));
                } catch (Throwable) {
                    continue;
                }
                if ($cand !== null && ($prev === null || ($cand['id'] ?? 0) > $prev)) {
                    $reply = $cand;
                    break;
                }
                if ($reply === null && $cand !== null && !self::isStandby((string) $cand['text'])) {
                    $reply = $cand; // no new msg yet; fall back to last substantive one
                }
            }

            if ($reply === null) {
                return ['status' => 'unknown', 'until' => null, 'raw' => '', 'checked_at' => \time(), 'cached' => false];
            }
            $parsed = self::parse((string) $reply['text']);
        } catch (Throwable $e) {
            return ['status' => 'error', 'until' => null, 'raw' => $e->getMessage(), 'checked_at' => \time(), 'cached' => false];
        }

        $out = \array_merge($parsed, ['checked_at' => \time(), 'cached' => false]);
        @\file_put_contents(
            ApiClient::cacheDir() . '/spambot-' . $session . '.json',
            \json_encode($out, JSON_UNESCAPED_SLASHES)
        );
        return $out;
    }

    private static function isStandby(string $text): bool
    {
        $t = \strtolower($text);
        return \str_contains($t, 'send /start if you need me')
            || \str_contains($t, 'i can help') && \strlen($t) < 80;
    }

    /** @return array{id:int,text:string}|null */
    private static function topIncoming(mixed $history): ?array
    {
        if (!\is_array($history)) {
            return null;
        }
        foreach ((array) ($history['messages'] ?? []) as $m) {
            if (($m['out'] ?? true) === false) {
                $text = $m['message'] ?? '';
                if (\is_string($text) && $text !== '') {
                    return ['id' => (int) ($m['id'] ?? 0), 'text' => $text];
                }
            }
        }
        return null;
    }

    /** @return array{status:string, until:int|null, raw:string} */
    private static function parse(string $text): array
    {
        $t = \strtolower($text);
        if (\str_contains($t, 'good news') || \str_contains($t, 'no limits')) {
            return ['status' => 'ok', 'until' => null, 'raw' => $text];
        }
        if (\preg_match('/(?:blocked|banned)[^.]*(?:until|до)\s*[:\-]?\s*([a-z0-9,\s:\-\/]+)/iu', $text, $m) === 1
            || \preg_match('/until\s+([a-z0-9,\s:\-\/]+)/i', $text, $m) === 1) {
            $ts = \strtotime(\trim($m[1]));
            if ($ts !== false) {
                return ['status' => 'limited', 'until' => $ts, 'raw' => $text];
            }
        }
        if (\str_contains($t, 'afraid') || \str_contains($t, 'annoying') || \str_contains($t, 'limited')
            || \str_contains($t, 'spam')) {
            $until = null;
            if (\preg_match('/(?:until|till)\s+([a-z0-9,:\-\/ ]+)/i', $text, $mm) === 1) {
                $ts = \strtotime(\trim($mm[1]));
                $until = $ts !== false ? $ts : null;
            }
            $status = $until !== null ? 'limited' : 'warned';
            return ['status' => $status, 'until' => $until, 'raw' => $text];
        }
        if (\str_contains($t, 'permanently blocked') || \str_contains($t, 'permanent ban') || \str_contains($t, 'your account was blocked')) {
            return ['status' => 'banned', 'until' => null, 'raw' => $text];
        }
        return ['status' => 'unknown', 'until' => null, 'raw' => $text];
    }
}
