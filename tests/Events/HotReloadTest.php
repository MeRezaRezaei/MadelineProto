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

namespace danog\MadelineProto\Test\Events;

use Amp\DeferredFuture;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisSubscription;
use danog\MadelineProto\Events\EventBus;
use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

/**
 * Hot reload acceptance tests (Redis on tcp://127.0.0.1:16379, no auth).
 */
class HotReloadTest extends AsyncTestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private RedisClient $raw;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            $keys = $this->raw->execute('keys', 'madeline:dedup:*');
            if (\is_array($keys)) {
                foreach ($keys as $key) {
                    $this->raw->execute('del', $key);
                }
            }
            $keys = $this->raw->execute('keys', 'madeline:control:*');
            if (\is_array($keys)) {
                foreach ($keys as $key) {
                    $this->raw->execute('del', $key);
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Register a handler via control connection → it fires on next update.
     */
    public function testRegisterViaControlConnectionFiresOnNextUpdate(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $deferred = new DeferredFuture;

        // Three connections: publisher, subscriber (Connection A), control (Connection B)
        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $bus->controlRegister('updateNewMessage', 'handler-1', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = ['account_id' => $accountId, 'type' => $type, 'data' => $data];
            $deferred->complete();
        });

        $bus->start();

        // Push an update via publisher
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 42, 'message' => 'hello']);

        $deferred->getFuture()->await();

        $this->assertCount(1, $received);
        $this->assertSame('updateNewMessage', $received[0]['type']);
        $this->assertSame(100, $received[0]['data']['peer_id']);
        $this->assertSame(42, $received[0]['data']['message_id']);

        $bus->stop();
    }

    /**
     * Register second handler via control connection → both fire on update.
     */
    public function testRegisterSecondHandlerBothFire(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $expectedCount = 2;
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);

        // Register first handler
        $bus->controlRegister('updateNewMessage', 'handler-1', function (int $accountId, string $type, array $data) use (&$received, $deferred, $expectedCount): void {
            $received[] = 'handler-1';
            if (\count($received) >= $expectedCount) {
                $deferred->complete();
            }
        });

        // Register second handler
        $bus->controlRegister('updateNewMessage', 'handler-2', function (int $accountId, string $type, array $data) use (&$received, $deferred, $expectedCount): void {
            $received[] = 'handler-2';
            if (\count($received) >= $expectedCount) {
                $deferred->complete();
            }
        });

        $bus->start();

        // Push an update
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 42, 'message' => 'hello']);

        $deferred->getFuture()->await();

        $this->assertCount(2, $received);
        $this->assertContains('handler-1', $received);
        $this->assertContains('handler-2', $received);

        $bus->stop();
    }

    /**
     * Connection A (updates subscriber) never reconnects during reload.
     */
    public function testConnectionANeverReconnectsDuringReload(): void
    {
        $this->setTimeout(5.0);

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $bus->controlRegister('updateNewMessage', 'handler-1', function (): void {});

        $bus->start();
        $this->assertSame(0, $bus->getConnectionAReconnects());

        // Register second handler (simulates hot reload)
        $bus->controlRegister('updateNewMessage', 'handler-2', function (): void {});

        // Explicit reload
        $bus->reload();

        // Push a few updates to keep connection active
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 1]);
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 2]);

        // Give time for any potential reconnect
        \Amp\delay(0.2);

        $this->assertSame(0, $bus->getConnectionAReconnects(), 'Connection A must never reconnect during hot reload');

        $bus->stop();
    }

    /**
     * Daemon continues running after multiple register/unregister cycles.
     */
    public function testDaemonContinuesRunningAfterMultipleReloads(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $cycleCount = 0;
        $expectedPerCycle = 1;

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);

        $bus->controlRegister('updateNewMessage', 'handler-main', function (int $accountId, string $type, array $data) use (&$received, &$cycleCount, $expectedPerCycle): void {
            $received[] = ['cycle' => $cycleCount, 'message_id' => $data['message_id'] ?? 0];
        });

        $bus->start();

        // Cycle 1: initial handler
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 1]);
        \Amp\delay(0.1);
        $this->assertCount(1, $received);

        // Cycle 2: add handler
        $cycleCount = 2;
        $bus->controlRegister('updateNewMessage', 'handler-cycle2', function (int $accountId, string $type, array $data) use (&$received, &$cycleCount): void {
            $received[] = ['cycle' => $cycleCount, 'message_id' => $data['message_id'] ?? 0];
        });
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 2]);
        \Amp\delay(0.1);

        // Cycle 3: remove handler, add another
        $bus->controlUnregister('handler-cycle2');
        $cycleCount = 3;
        $bus->controlRegister('updateNewMessage', 'handler-cycle3', function (int $accountId, string $type, array $data) use (&$received, &$cycleCount): void {
            $received[] = ['cycle' => $cycleCount, 'message_id' => $data['message_id'] ?? 0];
        });
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 3]);
        \Amp\delay(0.1);

        // Cycle 4: reload
        $bus->reload();
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 4]);
        \Amp\delay(0.1);

        $bus->stop();

        // main fires every cycle; cycle handlers fire in their own cycles:
        // msg1 (main), msg2 (main+cycle2), msg3 (main+cycle3), msg4 (main+cycle3)
        $this->assertCount(7, $received);
        $ids = array_column($received, 'message_id');
        $this->assertSame([1, 2, 2, 3, 3, 4, 4], $ids);
    }

    /**
     * controlUnregister removes handler — it no longer fires.
     */
    public function testControlUnregisterRemovesHandler(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $bus->controlRegister('updateNewMessage', 'handler-1', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = $data;
            $deferred->complete();
        });

        $bus->start();

        // First update — should fire
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 1]);
        $deferred->getFuture()->await();
        $this->assertCount(1, $received);

        // Unregister
        $bus->controlUnregister('handler-1');

        // Second update — should NOT fire (give a short window)
        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 2]);
        \Amp\delay(0.2);

        $this->assertCount(1, $received, 'Unregistered handler must not fire');

        $bus->stop();
    }

    /**
     * reload() rebuilds listeners from handlerRegistry.
     */
    public function testReloadRebuildsListenersFromRegistry(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $deferred = new DeferredFuture;
        $expectedCount = 2;

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $bus->controlRegister('updateNewMessage', 'handler-1', function (int $accountId, string $type, array $data) use (&$received, $deferred, $expectedCount): void {
            $received[] = 'handler-1';
            if (\count($received) >= $expectedCount) {
                $deferred->complete();
            }
        });
        $bus->controlRegister('updateNewMessage', 'handler-2', function (int $accountId, string $type, array $data) use (&$received, $deferred, $expectedCount): void {
            $received[] = 'handler-2';
            if (\count($received) >= $expectedCount) {
                $deferred->complete();
            }
        });

        // Simulate external process adding handlers then calling reload
        $bus->reload();

        $bus->start();

        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 42]);

        $deferred->getFuture()->await();

        $this->assertCount(2, $received);
        $this->assertContains('handler-1', $received);
        $this->assertContains('handler-2', $received);

        $bus->stop();
    }
}