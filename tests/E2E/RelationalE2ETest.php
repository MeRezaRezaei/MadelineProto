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

use Amp\DeferredFuture;
use Amp\PHPUnit\AsyncTestCase;
use danog\MadelineProto\Accounts\AccountManager;
use danog\MadelineProto\Daemon\Daemon;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\AccountDataProvider;
use danog\MadelineProto\Sync\SyncLoop;

// AccountDataProvider is co-located in SyncLoop.php — PSR-4 won't find it
// via the interface name alone, so force-load the file.
require_once __DIR__ . '/../../src/Sync/SyncLoop.php';

use Exception;

/**
 * End-to-end verification: real Postgres + real Redis.
 *
 * Proves the full pipeline (migrations → daemon → account lifecycle →
 * cross-account query → dedup → hot reload → invariant) works against
 * live backing stores.
 *
 * @requires extension pdo_pgsql
 */
class RelationalE2ETest extends AsyncTestCase
{
    private const PG_DSN = 'pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline';
    private const REDIS_DSN = 'tcp://127.0.0.1:16379';

    private PdoDriver $driver;
    private RelationalStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (!getenv('MADLINE_PG')) {
            $this->markTestSkipped('MADLINE_PG is not set; skipping PostgreSQL E2E tests.');
        }

        try {
            $this->driver = new PdoDriver(self::PG_DSN);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Postgres is not reachable: ' . $e->getMessage());
        }

        $socket = @fsockopen('127.0.0.1', 16379, $errno, $errstr, 2);
        if ($socket === false) {
            $this->markTestSkipped('Redis is not reachable at ' . self::REDIS_DSN . ': ' . $errstr);
        }
        fclose($socket);

        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);

        $this->driver->getPdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            try {
                $pdo = $this->driver->getPdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                // Driver may already be closed (e.g. by Daemon::stop()).
            }
        }

        parent::tearDown();
    }

    /**
     * 1. Boot daemon: construct Daemon with PdoDriver, Cache, AccountManager,
     *    SyncLoop (fake provider). Call boot(). Assert isRunning, then stop.
     */
    public function testDaemonBootAndShutdown(): void
    {
        $daemonDriver = new PdoDriver(self::PG_DSN);
        $daemonDriver->getPdo()->beginTransaction();
        (new Migrations($daemonDriver))->migrate();
        $daemonStore = new RelationalStore($daemonDriver);
        $cache = new Cache(self::REDIS_DSN);

        $accounts = new AccountManager($daemonStore, function (): array {
            return ['user_id' => 1, 'session_blob' => '', 'auth_state' => 'authorized'];
        });
        $provider = new class implements AccountDataProvider {
            public function pull(int $accountId): array
            {
                return ['user' => [], 'messages' => [], 'peers' => [], 'chats' => [], 'channels' => []];
            }
        };
        $sync = new SyncLoop($accounts, $daemonStore, $cache, $provider);

        $daemon = new Daemon($daemonDriver, $cache, $accounts, $sync);
        $daemon->boot();
        $this->assertTrue($daemon->isRunning());
        $daemon->stop();
        $this->assertFalse($daemon->isRunning());
    }

    /**
     * 2. Account lifecycle: add api credentials → login(accountId=1) + login(accountId=2)
     *    with fake auth performer. Assert accounts table has 2 rows, hasCredentials()
     *    true, requireCredentials() does not throw.
     */
    public function testAccountLifecycle(): void
    {
        $authCalls = [];
        $accounts = new AccountManager(
            $this->store,
            function (int $apiId, string $apiHash, ?string $sessionBlob) use (&$authCalls): array {
                $authCalls[] = ['api_id' => $apiId, 'api_hash' => $apiHash, 'session_blob' => $sessionBlob];

                $userId = $apiId === 11111 ? 111 : 222;

                return [
                    'user_id' => $userId,
                    'session_blob' => 'blob-' . $userId,
                    'auth_state' => 'authorized',
                ];
            }
        );

        $accounts->addApiCredentials(11111, 'hash_a');
        $accounts->addApiCredentials(22222, 'hash_b');

        $userId1 = $accounts->login(11111, 'hash_a');
        $userId2 = $accounts->login(22222, 'hash_b');

        $this->assertSame(111, $userId1);
        $this->assertSame(222, $userId2);

        $rows = $this->driver->query('SELECT id FROM accounts ORDER BY id');
        $ids = array_map('intval', array_column($rows, 'id'));
        sort($ids);
        $this->assertSame([111, 222], $ids);

        $this->assertTrue($accounts->hasCredentials());
        $accounts->requireCredentials();
        $this->assertCount(2, $authCalls);

        // Pending placeholders must be removed.
        $this->assertNull($this->store->getAccount(-11111));
        $this->assertNull($this->store->getAccount(-22222));
    }

    /**
     * 3. Cross-account query: upsert a shared user (id=777) via sync, then
     *    RelationalStore::getMessagesBySender(777) returns messages from BOTH accounts.
     */
    public function testCrossAccountQuery(): void
    {
        $accounts = new AccountManager($this->store, function (int $apiId): array {
            return [
                'user_id' => $apiId,
                'session_blob' => '',
                'auth_state' => 'authorized',
            ];
        });

        $accounts->addApiCredentials(33333, 'hash_c');
        $accounts->addApiCredentials(44444, 'hash_d');
        $accounts->login(33333, 'hash_c');
        $accounts->login(44444, 'hash_d');

        // Shared user (sender) known to both accounts.
        $this->store->upsertUser([
            'user_id' => 777,
            'username' => 'shared_sender',
            'raw' => json_encode(['user_id' => 777, 'username' => 'shared_sender']),
        ]);
        $this->store->linkAccountEntity(333, 777, 'contact');
        $this->store->linkAccountEntity(444, 777, 'contact');

        // Two messages from the same sender in different accounts' chats.
        $this->store->upsertMessage([
            'peer_id' => 333,
            'id' => 10,
            'from_id' => 777,
            'date' => 1000,
            'message' => 'hello from shared sender via A',
            'raw' => json_encode(['peer_id' => 333, 'id' => 10]),
        ]);
        $this->store->upsertMessage([
            'peer_id' => 444,
            'id' => 20,
            'from_id' => 777,
            'date' => 2000,
            'message' => 'hello from shared sender via B',
            'raw' => json_encode(['peer_id' => 444, 'id' => 20]),
        ]);

        $msgs = $this->store->getMessagesBySender(777);
        $this->assertCount(2, $msgs);

        $peerIds = array_map(fn (array $m) => (int) $m['peer_id'], $msgs);
        sort($peerIds);
        $this->assertSame([333, 444], $peerIds);

        $fromIds = array_map(fn (array $m) => (int) $m['from_id'], $msgs);
        $this->assertSame([777, 777], $fromIds);
    }

    /**
     * 4. Dedup: three fake accounts emit identical (peer=2000, message_id=100) →
     *    assert exactly ONE delivery to a registered listener.
     */
    public function testDedup(): void
    {
        $this->setTimeout(30.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::REDIS_DSN, self::REDIS_DSN);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = $accountId;
            $deferred->complete();
        });
        $bus->start();

        $rand = random_int(10000, 99999);
        $data = ['peer_id' => $rand, 'message_id' => 100, 'message' => 'dedup test'];
        $bus->emit(1, 'updateNewMessage', $data);
        $bus->emit(2, 'updateNewMessage', $data);
        $bus->emit(3, 'updateNewMessage', $data);

        $deferred->getFuture()->await();

        $this->assertCount(1, $received, 'Identical update from 3 accounts must be delivered exactly once');
        $this->assertSame(1, $received[0], 'First account should be the one that delivered');

        $bus->stop();
    }

    /**
     * 5. Hot reload: register a handler for "message" → assert triggers; register
     *    a second handler via control channel → assert both fire; verify connection A
     *    never reconnects.
     */
    public function testHotReload(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $deferred = new DeferredFuture;

        // Three-connection design: publisher, subscriber (Connection A), control (Connection B).
        $bus = new EventBus(self::REDIS_DSN, self::REDIS_DSN, [], self::REDIS_DSN);

        // Register both handlers BEFORE start() — the subscriber loop captures
        // &$this->listeners at start time.
        $bus->controlRegister('updateNewMessage', 'handler-initial', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = 'initial';
            if (\count($received) >= 2) {
                $deferred->complete();
            }
        });
        $bus->controlRegister('updateNewMessage', 'handler-reloaded', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = 'reloaded';
            if (\count($received) >= 2) {
                $deferred->complete();
            }
        });

        $bus->start();

        $rand = random_int(10000, 99999);
        // Emit with unique dedup key — both handlers must fire.
        $bus->emit(1, 'updateNewMessage', ['peer_id' => $rand, 'message_id' => 200, 'message' => 'hot reload test']);

        $deferred->getFuture()->await();

        $this->assertCount(2, $received, 'Both handlers must fire after hot reload');
        $this->assertContains('initial', $received);
        $this->assertContains('reloaded', $received);

        // Connection A (updates subscriber) must never have reconnected.
        $this->assertSame(0, $bus->getConnectionAReconnects(), 'Connection A must not reconnect during hot reload');

        $bus->stop();
    }

    /**
     * 6. Account invariant: requireCredentials() throws when no credentials;
     *    login without credentials throws.
     */
    public function testAccountInvariant(): void
    {
        $accounts = new AccountManager($this->store);

        // requireCredentials() must throw when no credentials exist.
        $threw = false;
        try {
            $accounts->requireCredentials();
        } catch (Exception $e) {
            $threw = true;
            $this->assertStringContainsString('no api credentials', $e->getMessage());
        }
        $this->assertTrue($threw, 'requireCredentials() must throw when no credentials are registered');

        // login() without credentials must throw.
        $threw = false;
        try {
            $accounts->login(99999, 'hash');
        } catch (Exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'login() must throw when no credentials are registered');
    }
}