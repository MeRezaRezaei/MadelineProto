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
use function Amp\async;

/**
 * Async wrapper around the synchronous {@see RelationalStore}.
 *
 * Reads are served through {@see Cache}: on a miss the value is fetched from
 * the store and cached (default TTL). Upserts update the store and then
 * invalidate exactly the affected key(s) — never more, never less.
 */
final class CachedStore
{
    private RelationalStore $store;
    private Cache $cache;
    private int $ttl;

    public function __construct(RelationalStore $store, Cache $cache, int $ttlSeconds = 300)
    {
        $this->store = $store;
        $this->cache = $cache;
        $this->ttl = $ttlSeconds;
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    private function readThrough(string $key, callable $fetch): Future
    {
        return async(function () use ($key, $fetch): ?array {
            $cached = $this->cache->get($key)->await();
            if ($cached !== null) {
                return json_decode($cached, true, 512, JSON_THROW_ON_ERROR);
            }

            $row = $fetch();
            if ($row !== null) {
                $this->cache->set($key, json_encode($row, JSON_THROW_ON_ERROR), $this->ttl)->await();
            }

            return $row;
        });
    }

    /**
     * @return Future<void>
     */
    private function invalidate(string ...$keys): Future
    {
        return async(function () use ($keys): void {
            if ($keys !== []) {
                $this->cache->delete(...$keys)->await();
            }
        });
    }

    // ---------------------------------------------------------------------
    // reads
    // ---------------------------------------------------------------------

    /**
     * @return Future<?array<string, mixed>>
     */
    public function getUser(int $id): Future
    {
        return $this->readThrough(Cache::userKey($id), fn () => $this->store->getUser($id));
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    public function getChat(int $id): Future
    {
        return $this->readThrough(Cache::chatKey($id), fn () => $this->store->getChat($id));
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    public function getMessage(int $peerId, int $id): Future
    {
        return $this->readThrough(Cache::messageKey($peerId, $id), fn () => $this->store->getMessage($peerId, $id));
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    public function getFile(int $volumeId, int $localId): Future
    {
        return $this->readThrough(Cache::fileKey($volumeId, $localId), fn () => $this->store->getFile($volumeId, $localId));
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    public function getAccount(int $id): Future
    {
        return $this->readThrough(Cache::accountKey($id), fn () => $this->store->getAccount($id));
    }

    /**
     * @return Future<?array<string, mixed>>
     */
    public function resolvePeer(string $usernameOrPhone): Future
    {
        return $this->readThrough(Cache::peerKey($usernameOrPhone), fn () => $this->store->resolvePeer($usernameOrPhone));
    }

    // ---------------------------------------------------------------------
    // writes (upsert + exact invalidation)
    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @return Future<void>
     */
    public function upsertUser(array $user): Future
    {
        return async(function () use ($user): void {
            $this->store->upsertUser($user);
            $keys = [Cache::userKey((int) $user['user_id'])];
            if (isset($user['username'])) {
                $keys[] = Cache::peerKey((string) $user['username']);
            }
            if (isset($user['phone'])) {
                $keys[] = Cache::peerKey((string) $user['phone']);
            }
            $this->invalidate(...$keys)->await();
        });
    }

    /**
     * @param array<string, mixed> $chat
     * @return Future<void>
     */
    public function upsertChat(array $chat): Future
    {
        return async(function () use ($chat): void {
            $this->store->upsertChat($chat);
            $keys = [Cache::chatKey((int) $chat['id'])];
            if (isset($chat['username'])) {
                $keys[] = Cache::peerKey((string) $chat['username']);
            }
            $this->invalidate(...$keys)->await();
        });
    }

    /**
     * @param array<string, mixed> $channel
     * @return Future<void>
     */
    public function upsertChannel(array $channel): Future
    {
        return async(function () use ($channel): void {
            $this->store->upsertChannel($channel);
            $keys = [Cache::channelKey((int) $channel['id'])];
            if (isset($channel['username'])) {
                $keys[] = Cache::peerKey((string) $channel['username']);
            }
            $this->invalidate(...$keys)->await();
        });
    }

    /**
     * @param array<string, mixed> $msg
     * @return Future<void>
     */
    public function upsertMessage(array $msg): Future
    {
        return async(function () use ($msg): void {
            $this->store->upsertMessage($msg);
            $this->invalidate(Cache::messageKey((int) $msg['peer_id'], (int) $msg['id']))->await();
        });
    }

    /**
     * @return Future<void>
     */
    public function upsertAccount(int $id, int $apiId, string $apiHash, ?string $authState, ?string $sessionBlob = null): Future
    {
        return async(function () use ($id, $apiId, $apiHash, $authState, $sessionBlob): void {
            $this->store->upsertAccount($id, $apiId, $apiHash, $authState, $sessionBlob);
            $this->invalidate(Cache::accountKey($id))->await();
        });
    }

    /**
     * @return Future<void>
     */
    public function upsertFile(int $volumeId, int $localId, string $fileReferenceBytes, string $type): Future
    {
        return async(function () use ($volumeId, $localId, $fileReferenceBytes, $type): void {
            $this->store->upsertFile($volumeId, $localId, $fileReferenceBytes, $type);
            $this->invalidate(Cache::fileKey($volumeId, $localId))->await();
        });
    }
}
