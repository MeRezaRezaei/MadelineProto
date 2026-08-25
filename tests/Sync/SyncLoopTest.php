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
use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Sync\AccountDataProvider;
use danog\MadelineProto\Sync\SyncLoop;
use PHPUnit\Framework\TestCase;

/**
 * Background sync loop tests (SQLite + real Redis 16379 + fake provider).
 */
class SyncLoopTest extends TestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private PdoDriver $driver;
    private RelationalStore $store;
    private AccountManager $accounts;
    private Cache $cache;
    private RedisClient $raw;
    private string $prefix;

    protected function setUp(): void
    {
        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $e->getMessage());
        }

        $this->prefix = 'mp-sync-' . bin2hex(random_bytes(4)) . ':';
        $this->cache = new Cache($this->raw, $this->prefix);

        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
        $this->accounts = new AccountManager($this->store);
    }

    /**
     * Fake provider: account A (1) and account B (2) both reference the SAME
     * user (id 777) plus one distinct message each (different peer_id, same
     * from_id 777).
     */
    private function fakeProvider(): AccountDataProvider
    {
        return new class implements AccountDataProvider {
            public function pull(int $accountId): array
            {
                $user = [
                    'user_id' => 777,
                    'access_hash' => '777',
                    'username' => 'seven',
                    'phone' => '+777',
                    'first_name' => 'Seven',
                    'raw' => json_encode(['user_id' => 777, 'username' => 'seven']),
                ];

                if ($accountId === 1) {
                    $message = [
                        'peer_id' => 11,
                        'id' => 1,
                        'from_id' => 777,
                        'date' => 1000,
                        'message' => 'from A',
                        'raw' => json_encode(['peer_id' => 11, 'id' => 1]),
                    ];
                } else {
                    $message = [
                        'peer_id' => 22,
                        'id' => 2,
                        'from_id' => 777,
                        'date' => 2000,
                        'message' => 'from B',
                        'raw' => json_encode(['peer_id' => 22, 'id' => 2]),
                    ];
                }

                return [
                    'user' => $user,
                    'messages' => [$message],
                    'peers' => [
                        ['peer_id' => 777, 'type' => 'user', 'username' => 'seven', 'phone' => '+777'],
                    ],
                    'chats' => [],
                    'channels' => [],
                ];
            }
        };
    }

    public function testTickProvesSingleSourceOfTruthAndCacheInvalidation(): void
    {
        // Two logged-in accounts, both "are" Telegram user 777.
        $this->store->upsertAccount(1, 111, 'hashA', 'authorized');
        $this->store->upsertAccount(2, 222, 'hashB', 'authorized');

        $sync = new SyncLoop(
            $this->accounts,
            $this->store,
            $this->cache,
            $this->fakeProvider(),
            30
        );

        // Seed cache with stale values that the sync pass must invalidate.
        $this->cache->set(Cache::userKey(777), 'stale')->await();
        $this->cache->set(Cache::messageKey(11, 1), 'stale')->await();
        $this->cache->set(Cache::messageKey(22, 2), 'stale')->await();
        $this->cache->set(Cache::peerKey('seven'), 'stale')->await();
        $this->cache->set(Cache::peerKey('+777'), 'stale')->await();

        $sync->tick();

        // Single source of truth: one users row, two account_entities links.
        $users = $this->driver->query('SELECT user_id FROM users');
        $this->assertCount(1, $users, 'exactly one users row');
        $this->assertSame(777, (int) $users[0]['user_id']);

        $links = $this->driver->query('SELECT account_id, entity_id, relationship FROM account_entities ORDER BY account_id');
        $this->assertCount(2, $links, 'two account→entity links (A→777, B→777)');
        $this->assertSame([1, 777], [(int) $links[0]['account_id'], (int) $links[0]['entity_id']]);
        $this->assertSame([2, 777], [(int) $links[1]['account_id'], (int) $links[1]['entity_id']]);
        $this->assertSame('self', $links[0]['relationship']);

        // Two message rows, both attributable to sender 777 (cross-account).
        $messages = $this->driver->query('SELECT peer_id, id FROM messages ORDER BY peer_id');
        $this->assertCount(2, $messages);
        $this->assertSame([11, 22], [(int) $messages[0]['peer_id'], (int) $messages[1]['peer_id']]);

        $bySender = $this->store->getMessagesBySender(777);
        $this->assertCount(2, $bySender);

        // Cache invalidation: affected keys are now absent in Redis.
        $this->assertFalse($this->cache->exists(Cache::userKey(777))->await());
        $this->assertFalse($this->cache->exists(Cache::messageKey(11, 1))->await());
        $this->assertFalse($this->cache->exists(Cache::messageKey(22, 2))->await());
        $this->assertFalse($this->cache->exists(Cache::peerKey('seven'))->await());
        $this->assertFalse($this->cache->exists(Cache::peerKey('+777'))->await());
    }

    public function testTickIsIdempotentNoDuplicates(): void
    {
        $this->store->upsertAccount(1, 111, 'hashA', 'authorized');
        $this->store->upsertAccount(2, 222, 'hashB', 'authorized');

        $sync = new SyncLoop(
            $this->accounts,
            $this->store,
            $this->cache,
            $this->fakeProvider(),
            30
        );

        $sync->tick();
        // Populate cache again, then prove a second pass re-invalidates AND
        // does not duplicate rows.
        $this->cache->set(Cache::userKey(777), 'stale2')->await();

        $sync->tick();

        $this->assertCount(1, $this->driver->query('SELECT user_id FROM users'));
        $this->assertCount(2, $this->driver->query('SELECT peer_id, id FROM messages'));
        $this->assertFalse($this->cache->exists(Cache::userKey(777))->await());
    }
}
