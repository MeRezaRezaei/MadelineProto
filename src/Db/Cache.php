<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Db;

use Amp\Future;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\async;
use RuntimeException;

/**
 * Async Redis cache fronting the relational store.
 *
 * Values are stored verbatim (string in / string out) so the cache never
 * alters what the {@see RelationalStore} produced. Keys are namespaced via the
 * static {@see Cache::userKey()}, {@see Cache::peerKey()}, … helpers and an
 * optional instance prefix.
 */
final class Cache
{
    private RedisClient $redis;
    private string $prefix;

    /**
     * @param RedisClient|string $redis A connected {@see RedisClient} or a DSN
     *                                 string (e.g. tcp://127.0.0.1:16379).
     */
    public function __construct(RedisClient|string $redis, string $prefix = '')
    {
        if (\is_string($redis)) {
            try {
                $redis = \Amp\Redis\createRedisClient(RedisConfig::fromUri($redis));
            } catch (\Throwable $e) {
                throw new RuntimeException('Failed to connect to Redis: ' . $e->getMessage(), 0, $e);
            }
        }
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    private function key(string $key): string
    {
        return $this->prefix !== '' ? $this->prefix . ':' . $key : $key;
    }

    /**
     * Fetch a value, resolving to string|null.
     */
    public function get(string $key): Future
    {
        return async(fn () => $this->redis->get($this->key($key)));
    }

    /**
     * Store a value, optionally with a TTL in seconds.
     */
    public function set(string $key, string $value, ?int $ttlSeconds = null): Future
    {
        $options = $ttlSeconds !== null ? (new SetOptions())->withTtl($ttlSeconds) : null;

        return async(fn () => $this->redis->set($this->key($key), $value, $options));
    }

    /**
     * Remove one or more keys.
     *
     * @param string ...$keys
     */
    public function delete(string ...$keys): Future
    {
        $namespaced = array_map($this->key(...), $keys);

        return async(fn () => $this->redis->delete(...$namespaced));
    }

    /**
     * Whether a key exists.
     */
    public function exists(string $key): Future
    {
        return async(fn () => $this->redis->has($this->key($key)));
    }

    // ---------------------------------------------------------------------
    // namespaced key builders
    // ---------------------------------------------------------------------

    public static function userKey(int $id): string
    {
        return 'entity:user:' . $id;
    }

    public static function chatKey(int $id): string
    {
        return 'entity:chat:' . $id;
    }

    public static function channelKey(int $id): string
    {
        return 'entity:channel:' . $id;
    }

    public static function messageKey(int $peerId, int $id): string
    {
        return 'msg:' . $peerId . ':' . $id;
    }

    public static function peerKey(string $usernameOrPhone): string
    {
        return 'peer:' . $usernameOrPhone;
    }

    public static function accountKey(int $id): string
    {
        return 'account:' . $id;
    }

    public static function fileKey(int $volumeId, int $localId): string
    {
        return 'file:' . $volumeId . ':' . $localId;
    }

}
