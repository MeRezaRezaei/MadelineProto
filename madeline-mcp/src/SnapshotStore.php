<?php

declare(strict_types=1);

namespace MadelineMcp;

/**
 * Holds frozen-in-time ordered listings so the AI can paginate a STABLE sort
 * instead of re-fetching. Telegram reshuffles dialog order constantly, so a
 * second raw list call returns a different ordering and confuses the model.
 *
 * A token references an ordered set of already-projected rows plus an advancing
 * cursor. Passing the token back yields the next slice of the SAME order.
 * Omitting the token starts a fresh (current-moment) sort.
 *
 * The token is a SHORT-LIVED server-side cache: it is only valid for
 * TTL_SECONDS after creation (Telegram's positions and contents drift, so a
 * snapshot from long ago would be misleading). After expiry the token is gone
 * and the caller must start a fresh current-moment snapshot.
 */
final class SnapshotStore
{
    private const MAX = 500;
    private const TTL_SECONDS = 300;

    /** token => ['items' => array, 'off' => int, 'meta' => array, 'expires' => int] */
    private static array $store = [];
    /** insertion order, for LRU eviction */
    private static array $order = [];

    public static function create(array $items, array $meta = [], ?int $ttl = null): string
    {
        $token = bin2hex(random_bytes(16));
        $ttl = $ttl ?? self::TTL_SECONDS;
        self::$store[$token] = [
            'items' => array_values($items),
            'off' => 0,
            'meta' => $meta,
            'expires' => \time() + $ttl,
        ];
        self::$order[] = $token;
        if (\count(self::$store) > self::MAX) {
            $old = array_shift(self::$order);
            unset(self::$store[$old]);
        }

        return $token;
    }

    public static function exists(string $token): bool
    {
        return self::isLive($token);
    }

    public static function meta(string $token): ?array
    {
        return self::isLive($token) ? (self::$store[$token]['meta'] ?? null) : null;
    }

    /** Returns the next slice [off, off+limit) and advances the cursor. */
    public static function take(string $token, int $limit): ?array
    {
        if (!self::isLive($token)) {
            return null;
        }
        $s =& self::$store[$token];
        $slice = \array_slice($s['items'], $s['off'], $limit);
        $s['off'] += \count($slice);

        return [
            'items' => $slice,
            'total' => \count($s['items']),
            'returned' => \count($slice),
            'done' => $s['off'] >= \count($s['items']),
            'meta' => $s['meta'],
        ];
    }

    /** Append items (e.g. an older page) and optionally update meta. */
    public static function extend(string $token, array $items, array $meta = []): void
    {
        if (!self::isLive($token)) {
            return;
        }
        $s =& self::$store[$token];
        $s['items'] = \array_merge($s['items'], \array_values($items));
        if ($meta !== []) {
            $s['meta'] = $meta;
        }
    }

    /** True only while the token exists AND has not passed its TTL. */
    private static function isLive(string $token): bool
    {
        if (!isset(self::$store[$token])) {
            return false;
        }
        if (\time() > (self::$store[$token]['expires'] ?? 0)) {
            unset(self::$store[$token]);
            $k = \array_search($token, self::$order, true);
            if ($k !== false) {
                unset(self::$order[$k]);
            }

            return false;
        }

        return true;
    }
}
