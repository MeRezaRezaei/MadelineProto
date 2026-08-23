<?php

declare(strict_types=1);

namespace MadelineMcp;

/**
 * Shapes tool results for an AI consumer: compact JSON, no duplicated
 * structures, no TL bitfield noise, no base64 blobs, bounded strings.
 * Disable entirely with MADELINE_MCP_RAW=1.
 */
final class ResponseSanitizer
{
    /** TL internals the AI never needs. */
    private const DROP_KEYS = [
        'flags', 'flags2', 'flags3', 'access_hash', 'access_hashes',
        'stripped_thumb', 'thumb', 'thumb_location', 'file_reference',
    ];

    private const BLOB_RE = '/^[A-Za-z0-9+\/=\r\n]{256,}$/';

    /**
     * Compact projection applied to curated hot tools so the AI sees exactly
     * the actionable fields. Everything else goes through clean().
     */
    public static function project(string $tool, mixed $result): mixed
    {
        if (!\is_array($result) || isset($result['_error'])) {
            return $result;
        }

        switch ($tool) {
            case 'bot.invoke':
                // One canonical button list: text + how to act on it.
                $buttons = [];
                foreach ((array) ($result['buttons'] ?? []) as $text => $meta) {
                    $entry = ['text' => (string) $text, 'type' => (string) ($meta['type'] ?? 'callback')];
                    if (($meta['type'] ?? '') === 'url') {
                        $entry['url'] = (string) ($meta['url'] ?? '');
                    }
                    $buttons[] = $entry;
                }
                unset(
                    $result['buttons'], $result['new_inline_buttons'],
                    $result['new_buttons_full'], $result['wait_seconds']
                );
                \uasort($buttons, fn ($a, $b) => strcmp((string) $a['text'], (string) $b['text']));
                $result['buttons'] = \array_values($buttons);
                return $result;

            case 'resolve_peer':
                $chat = (array) ($result['Chat'] ?? []);
                $user = (array) ($result['User'] ?? []);
                $out = ['type' => (string) ($result['type'] ?? '')];
                foreach ([
                    'id' => $chat['id'] ?? $user['id'] ?? null,
                    'bot_api_id' => $result['bot_api_id'] ?? null,
                    'title' => $chat['title'] ?? null,
                    'username' => $chat['username'] ?? $user['username'] ?? null,
                    'first_name' => $user['first_name'] ?? null,
                    'last_name' => $user['last_name'] ?? null,
                    'is_bot' => isset($user['bot']) ? (bool) $user['bot'] : null,
                    'verified' => !empty($chat['verified']),
                ] as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $out[$k] = $v;
                    }
                }
                return $out;
        }
        return $result;
    }

    /**
     * Recursive prune: nulls gone, TL bitmask/hash keys gone, base64 blobs
     * replaced by size markers, long strings truncated with a visible marker.
     */
    public static function clean(mixed $d, int $maxStr = 2000): mixed
    {
        if (\is_string($d)) {
            if (\strlen($d) >= 256 && \preg_match(self::BLOB_RE, $d) === 1) {
                return '[blob ' . \strlen($d) . 'B]';
            }
            $len = \mb_strlen($d);
            return $len > $maxStr ? \mb_substr($d, 0, $maxStr) . "…[+$len ch]" : $d;
        }
        if (\is_array($d)) {
            $out = [];
            foreach ($d as $k => $v) {
                if (\is_string($k) && \in_array($k, self::DROP_KEYS, true)) {
                    continue;
                }
                if ($v === null) {
                    continue;
                }
                $out[$k] = self::clean($v, $maxStr);
            }
            return $out;
        }
        return $d;
    }
}
