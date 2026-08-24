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

    /** Upper bounds so a raw call can never blow the proxy's ~20KB cap. */
    private const MAX_MESSAGES = 25;
    private const MAX_DIALOGS = 50;
    private const MAX_LIST = 200;
    /** Preview length for message bodies inside a list scan (call_method). */
    private const LIST_TEXT = 200;

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
                // Event diff: compact per-event summaries.
                $result['response'] = \mb_substr((string) ($result['response'] ?? ''), 0, 1500);
                $events = [];
                foreach ((array) ($result['events'] ?? []) as $e) {
                    $events[] = [
                        'id' => (int) ($e['id'] ?? 0),
                        'type' => (string) ($e['type'] ?? ''),
                        'text' => \mb_substr((string) ($e['text'] ?? ''), 0, 300),
                        'n_buttons' => \is_array($e['buttons'] ?? null) ? \count($e['buttons']) : 0,
                    ];
                }
                $result['events'] = $events;
                return $result;

            case 'bot.read':
                $msgs = [];
                foreach ((array) ($result['messages'] ?? []) as $m) {
                    $msgs[] = [
                        'id' => (int) ($m['id'] ?? 0),
                        'out' => (bool) ($m['out'] ?? false),
                        'text' => \mb_substr((string) ($m['text'] ?? ''), 0, 300),
                        'n_buttons' => \is_array($m['inline_buttons'] ?? null) ? \count($m['inline_buttons']) : 0,
                    ];
                }
                $result['messages'] = $msgs;
                $btns = [];
                foreach ((array) ($result['inline_buttons'] ?? []) as $text => $meta) {
                    $entry = ['text' => (string) $text, 'type' => (string) ($meta['type'] ?? 'callback'), 'msg_id' => (int) ($meta['msg_id'] ?? 0)];
                    if (($meta['type'] ?? '') === 'url') {
                        $entry['url'] = (string) ($meta['url'] ?? '');
                    }
                    $btns[] = $entry;
                }
                $result['inline_buttons'] = \array_values($btns); // data stays server-side
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

            case 'call_method':
                // Raw TL passthrough: apply the same container projection used
                // by the curated conversation tools so the AI sees resolved,
                // bounded data instead of a 451KB blob.
                return self::projectContainers($result);
        }
        return $result;
    }

    /**
     * Reshape a curated listing result into the AI-facing SIMPLE STRUCTURE:
     * a top "about" section (what was asked: filter/sort/counts) and a "rows"
     * array where every record follows the same fixed field order.
     */
    public static function toSimple(string $tool, mixed $result): mixed
    {
        if (!\is_array($result) || isset($result['_error'])) {
            return $result;
        }
        switch ($tool) {
            case 'list_conversations':
                $rows = $result['conversations'] ?? [];
                $about = $result;
                unset($about['conversations']);
                return ['about' => $about, 'rows' => \array_values($rows)];
            case 'get_conversation':
                $rows = $result['messages'] ?? [];
                $about = $result;
                unset($about['messages']);
                return ['about' => $about, 'rows' => \array_values($rows)];
        }
        return $result;
    }

    /**
     * Generic projection for the heavy TL container shapes (messages, dialogs,
     * users, chats). Reused for every raw call_method result so the AI gets the
     * same clean shape whether it calls get_conversation or messages.getHistory.
     */
    private static function projectContainers(array $result): array
    {
        if (isset($result['_error'])) {
            return $result;
        }

        $users = [];
        foreach ((array) ($result['users'] ?? []) as $u) {
            if (\is_array($u) && isset($u['id'])) {
                $users[$u['id']] = $u;
            }
        }
        $chats = [];
        foreach ((array) ($result['chats'] ?? []) as $c) {
            if (\is_array($c) && isset($c['id'])) {
                $chats[$c['id']] = $c;
            }
        }
        $msgMap = [];
        foreach ((array) ($result['messages'] ?? []) as $m) {
            if (\is_array($m) && isset($m['id'])) {
                $msgMap[$m['id']] = $m;
            }
        }

        if (isset($result['messages']) && \is_array($result['messages'])) {
            $out = [];
            foreach (\array_slice($result['messages'], 0, self::MAX_MESSAGES) as $m) {
                $pm = \is_array($m) ? self::projectMessage($m, $users, $chats) : $m;
                if (\is_array($pm) && isset($pm['text']) && \mb_strlen($pm['text']) > self::LIST_TEXT) {
                    $pm['text'] = \mb_substr($pm['text'], 0, self::LIST_TEXT) . '…';
                }
                $out[] = $pm;
            }
            $result['messages'] = $out;
        }
        if (isset($result['dialogs']) && \is_array($result['dialogs'])) {
            $out = [];
            foreach (\array_slice($result['dialogs'], 0, self::MAX_DIALOGS) as $d) {
                $out[] = \is_array($d) ? self::projectDialog($d, $users, $chats, $msgMap) : $d;
            }
            $result['dialogs'] = $out;
        }
        if (isset($result['users']) && \is_array($result['users'])) {
            $out = [];
            foreach ($result['users'] as $u) {
                $out[] = \is_array($u) ? self::projectUser($u) : $u;
            }
            // Bound the reference table so participant-style calls
            // (channels.getParticipants, messages.getDialogs) stay under the
            // proxy cap even if clean() is not applied downstream.
            $result['users'] = \array_slice($out, 0, self::MAX_LIST);
        }
        if (isset($result['chats']) && \is_array($result['chats'])) {
            $out = [];
            foreach ($result['chats'] as $c) {
                $out[] = \is_array($c) ? self::projectChat($c) : $c;
            }
            $result['chats'] = \array_slice($out, 0, self::MAX_LIST);
        }

        // Names are embedded in the projected messages/dialogs, but the
        // compacted users/chats reference tables are tiny after projection and
        // useful to keep (ids resolve to names without re-fetching). Only drop
        // the bulk messages list when dialogs already carry their previews.
        if (isset($result['dialogs'])) {
            unset($result['messages']);
        }

        return $result;
    }

    private static function projectMessage(array $m, array $users, array $chats): array
    {
        $fromId = $m['from_id'] ?? $m['peer_id'] ?? null;
        [$ftype, $fname] = \is_int($fromId) ? self::peerInfo((int) $fromId, $users, $chats) : [null, null];

        $text = '';
        $mediaType = 'text';
        if (isset($m['message']) && \is_string($m['message']) && $m['message'] !== '') {
            $text = $m['message'];
        } elseif (isset($m['media'])) {
            $mediaType = 'media:' . ((string) ($m['media']['_'] ?? 'unknown'));
            $text = $m['message'] ?? '';
        } elseif (isset($m['action'])) {
            $mediaType = 'action:' . ((string) ($m['action']['_'] ?? 'unknown'));
        }

        return [
            'id' => $m['id'] ?? null,
            'date' => $m['date'] ?? 0,
            'dir' => !empty($m['out']) ? 'out' : 'in',
            'from' => ['id' => $fromId, 'name' => $fname, 'type' => $ftype],
            'media_type' => $mediaType,
            'text' => $text,
            'edited' => isset($m['edit_date']),
            'reply_to' => ($m['reply_to']['reply_to_msg_id'] ?? null),
        ];
    }

    private static function projectDialog(array $d, array $users, array $chats, array $msgMap): array
    {
        $pid = $d['peer'] ?? null;
        [$ptype, $pname] = \is_int($pid) ? self::peerInfo((int) $pid, $users, $chats) : [null, null];

        $preview = '';
        $last = 0;
        $topId = $d['top_message'] ?? null;
        if ($topId !== null && isset($msgMap[$topId])) {
            $m = $msgMap[$topId];
            $last = $m['date'] ?? 0;
            if (isset($m['message']) && \is_string($m['message'])) {
                $preview = $m['message'];
            } elseif (isset($m['action'])) {
                $preview = '[' . ((string) ($m['action']['_'] ?? 'action')) . ']';
            }
        } elseif (isset($d['message']) && \is_string($d['message'])) {
            $preview = $d['message'];
        }

        return [
            'peer' => ['id' => $pid, 'name' => $pname, 'type' => $ptype],
            'unread' => $d['unread_count'] ?? 0,
            'pinned' => (bool) ($d['pinned'] ?? false),
            'preview' => \mb_substr($preview, 0, 80),
            'last_activity' => $last,
        ];
    }

    private static function projectUser(array $u): array
    {
        $name = \trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

        return [
            'id' => $u['id'] ?? null,
            'name' => $name !== '' ? $name : ($u['username'] ?? null),
            'username' => $u['username'] ?? null,
            'is_bot' => !empty($u['bot']),
        ];
    }

    private static function projectChat(array $c): array
    {
        $type = match ($c['_'] ?? '') {
            'channel' => !empty($c['megagroup']) ? 'supergroup' : 'channel',
            'chat' => 'group',
            default => (string) ($c['_'] ?? 'chat'),
        };

        return [
            'id' => $c['id'] ?? null,
            'title' => $c['title'] ?? null,
            'username' => $c['username'] ?? null,
            'type' => $type,
        ];
    }

    private static function peerInfo(int $pid, array $users, array $chats): array
    {
        if ($pid > 0) {
            $u = $users[$pid] ?? null;
            if ($u) {
                $name = \trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

                return ['user', $name !== '' ? $name : ($u['username'] ?? (string) $pid)];
            }

            return ['user', (string) $pid];
        }
        if (\str_starts_with((string) $pid, '-100')) {
            $c = $chats[\abs($pid)] ?? null;

            return $c ? ['channel', $c['title'] ?? (string) $pid] : ['channel', (string) $pid];
        }
        $c = $chats[\abs($pid)] ?? null;

        return $c ? ['group', $c['title'] ?? (string) $pid] : ['group', (string) $pid];
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
            if (\array_is_list($d) && \count($d) > self::MAX_LIST) {
                $d = \array_slice($d, 0, self::MAX_LIST);
            }
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
