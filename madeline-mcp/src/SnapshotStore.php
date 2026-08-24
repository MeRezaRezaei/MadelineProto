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
 */
final class SnapshotStore
{
    private const MAX = 500;

    /** token => ['items' => array, 'off' => int, 'meta' => array] */
    private static array $store = [];
    /** insertion order, for LRU eviction */
    private static array $order = [];

    public static function create(array $items, array $meta = []): string
    {
        $token = bin2hex(random_bytes(16));
        self::$store[$token] = ['items' => array_values($items), 'off' => 0, 'meta' => $meta];
        self::$order[] = $token;
        if (\count(self::$store) > self::MAX) {
            $old = array_shift(self::$order);
            unset(self::$store[$old]);
        }

        return $token;
    }

    public static function exists(string $token): bool
    {
        return isset(self::$store[$token]);
    }

    public static function meta(string $token): ?array
    {
        return self::$store[$token]['meta'] ?? null;
    }

    /** Returns the next slice [off, off+limit) and advances the cursor. */
    public static function take(string $token, int $limit): ?array
    {
        if (!isset(self::$store[$token])) {
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
        if (!isset(self::$store[$token])) {
            return;
        }
        $s =& self::$store[$token];
        $s['items'] = \array_merge($s['items'], \array_values($items));
        if ($meta !== []) {
            $s['meta'] = $meta;
        }
    }
}
