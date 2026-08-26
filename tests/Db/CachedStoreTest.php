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

namespace danog\MadelineProto\Test;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\Redis\createRedisClient;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\CachedStore;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

/**
 * CachedStore acceptance tests (SQLite DB + Redis on tcp://127.0.0.1:16379).
 */
class CachedStoreTest extends TestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private CachedStore $cached;
    private Cache $cache;
    private RedisClient $raw;
    private SpyRelationalStore $store;
    private PdoDriver $driver;
    private string $prefix;

    protected function setUp(): void
    {
        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $e->getMessage());
        }

        $this->prefix = 'mp-cs-' . bin2hex(random_bytes(4)) . ':';
        $this->cache = new Cache($this->raw, $this->prefix);

        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new SpyRelationalStore($this->driver);
        $this->cached = new CachedStore($this->store, $this->cache);
    }

    public function testCacheMissPopulatesCache(): void
    {
        $user = [
            'user_id' => 1001,
            'username' => 'carol',
            'raw' => json_encode(['user_id' => 1001, 'username' => 'carol']),
        ];
        $this->cached->upsertUser($user)->await();

        // First read is a miss → reads DB and populates the cache.
        $first = $this->cached->getUser(1001)->await();
        $this->assertNotNull($first);
        $this->assertSame('carol', $first['username']);

        $this->assertTrue($this->cache->exists(Cache::userKey(1001))->await());
    }

    public function testCacheHitServedWithoutDbRoundTrip(): void
    {
        $user = [
            'user_id' => 1002,
            'username' => 'dave',
            'raw' => json_encode(['user_id' => 1002, 'username' => 'dave']),
        ];
        $this->cached->upsertUser($user)->await();

        $this->cached->getUser(1002)->await(); // populate
        $dbCallsAfterPopulate = $this->store->getUserCalls;

        // Remove the row from the DB. A cache HIT must still return data.
        $this->driver->exec('DELETE FROM users WHERE user_id = 1002');

        $hit = $this->cached->getUser(1002)->await();
        $this->assertNotNull($hit);
        $this->assertSame('dave', $hit['username']);
        // No additional DB read happened on the cache hit.
        $this->assertSame($dbCallsAfterPopulate, $this->store->getUserCalls);
    }

    public function testUpsertInvalidatesCacheKey(): void
    {
        $user = [
            'user_id' => 1003,
            'username' => 'erin',
            'raw' => json_encode(['user_id' => 1003, 'username' => 'erin']),
        ];
        $this->cached->upsertUser($user)->await();
        $this->cached->getUser(1003)->await(); // populate
        $this->assertTrue($this->cache->exists(Cache::userKey(1003))->await());

        // Upsert again (e.g. updated first_name) → exact invalidation.
        $updated = $user + ['first_name' => 'Erinator'];
        $this->cached->upsertUser($updated)->await();

        $this->assertFalse($this->cache->exists(Cache::userKey(1003))->await());

        // Next read re-fetches from DB and returns the updated value.
        $again = $this->cached->getUser(1003)->await();
        $this->assertNotNull($again);
        $this->assertSame('Erinator', $again['first_name']);
    }

    public function testPeerCacheInvalidatedOnUserUpsert(): void
    {
        $user = [
            'user_id' => 1004,
            'username' => 'frank',
            'phone' => '+10000000004',
            'raw' => json_encode(['user_id' => 1004, 'username' => 'frank']),
        ];
        $this->cached->upsertUser($user)->await();

        $this->cached->resolvePeer('frank')->await();
        $this->assertTrue($this->cache->exists(Cache::peerKey('frank'))->await());

        // Re-upserting the same user invalidates the peer key too (exact).
        $this->cached->upsertUser($user + ['first_name' => 'Frankie'])->await();
        $this->assertFalse($this->cache->exists(Cache::peerKey('frank'))->await());
    }

    public function testMessageCacheInvalidation(): void
    {
        $msg = [
            'peer_id' => 7,
            'id' => 99,
            'from_id' => 1,
            'date' => 1234,
            'raw' => json_encode(['peer_id' => 7, 'id' => 99]),
        ];
        $this->cached->upsertMessage($msg)->await();
        $got = $this->cached->getMessage(7, 99)->await();
        $this->assertNotNull($got);
        $this->assertTrue($this->cache->exists(Cache::messageKey(7, 99))->await());

        $this->cached->upsertMessage($msg + ['message' => 'edited'])->await();
        $this->assertFalse($this->cache->exists(Cache::messageKey(7, 99))->await());
    }

    public function testChannelReadThroughAndInvalidation(): void
    {
        $channel = [
            'id' => 100200300,
            'title' => 'Chan',
            'username' => 'chan',
            'raw' => json_encode(['id' => 100200300]),
        ];
        $this->cached->upsertChannel($channel)->await();

        $got = $this->cached->getChannel(100200300)->await();
        $this->assertNotNull($got);
        $this->assertSame('Chan', $got['title']);
        $this->assertTrue($this->cache->exists(Cache::channelKey(100200300))->await());

        $this->cached->upsertChannel($channel + ['title' => 'Chan2'])->await();
        $this->assertFalse($this->cache->exists(Cache::channelKey(100200300))->await());
    }
}

/**
 * RelationalStore spy counting getUser() invocations.
 *
 * @internal
 */
final class SpyRelationalStore extends RelationalStore
{
    public int $getUserCalls = 0;

    public function getUser(int $id): ?array
    {
        $this->getUserCalls++;

        return parent::getUser($id);
    }
}
