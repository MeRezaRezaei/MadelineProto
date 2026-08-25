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
use danog\MadelineProto\Events\EventBus;
use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

/**
 * EventBus acceptance tests (Redis on tcp://127.0.0.1:16379, no auth).
 */
class EventBusTest extends AsyncTestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private RedisClient $raw;
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
            $this->raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $e->getMessage());
        }

        $this->prefix = 'mp-evtbus-' . bin2hex(random_bytes(4)) . ':';

        $this->cleanDedupKeys();
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            $this->cleanDedupKeys();
        }

        parent::tearDown();
    }

    private function cleanDedupKeys(): void
    {
        $keys = $this->raw->execute('keys', 'madeline:dedup:*');
        if (\is_array($keys)) {
            foreach ($keys as $key) {
                $this->raw->execute('del', $key);
            }
        }
    }

    /**
     * Two fake accounts publish the same update type → bus delivers
     * to a registered listener exactly once per publish (2 deliveries total).
     */
    public function testFanInTwoAccountsDeliverTwice(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = ['account_id' => $accountId, 'type' => $type, 'data' => $data];
            if (\count($received) >= 2) {
                $deferred->complete();
            }
        });

        $bus->start();

        $bus->emit(1, 'updateNewMessage', ['user_id' => 777, 'message' => 'hello from A']);
        $bus->emit(2, 'updateNewMessage', ['user_id' => 777, 'message' => 'hello from B']);

        $deferred->getFuture()->await();

        $this->assertCount(2, $received);
        $this->assertSame(1, $received[0]['account_id']);
        $this->assertSame(2, $received[1]['account_id']);
        $this->assertSame('updateNewMessage', $received[0]['type']);
        $this->assertSame('updateNewMessage', $received[1]['type']);

        $bus->stop();
    }

    /**
     * Listener receives the typed update (assert $data['user_id'] === 777).
     */
    public function testListenerReceivesTypedData(): void
    {
        $this->setTimeout(5.0);

        $captured = null;
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$captured, $deferred): void {
            $captured = $data;
            $deferred->complete();
        });

        $bus->start();

        $bus->emit(1, 'updateNewMessage', ['user_id' => 777, 'message' => 'test']);

        $deferred->getFuture()->await();

        $this->assertNotNull($captured);
        $this->assertSame(777, $captured['user_id']);
        $this->assertSame('test', $captured['message']);

        $bus->stop();
    }

    /**
     * Unregistered update types are NOT delivered (no spurious dispatch).
     */
    public function testUnregisteredTypeNotDelivered(): void
    {
        $this->setTimeout(5.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = $type;
            $deferred->complete();
        });

        $bus->start();

        // Publish an unregistered type, then the registered type
        $bus->emit(1, 'updateChatParticipant', ['user_id' => 999]);
        $bus->emit(1, 'updateNewMessage', ['user_id' => 777, 'message' => 'hello']);

        $deferred->getFuture()->await();

        // Only the registered type should appear
        $this->assertContains('updateNewMessage', $received);
        $this->assertNotContains('updateChatParticipant', $received);

        $bus->stop();
    }

    /**
     * stop() disconnects cleanly.
     */
    public function testStopDisconnectsCleanly(): void
    {
        $bus = new EventBus(self::DSN, self::DSN);
        $bus->on('updateNewMessage', function (): void {
            // no-op
        });

        $this->assertFalse($bus->isRunning());

        $bus->start();
        $this->assertTrue($bus->isRunning());

        $bus->stop();
        $this->assertFalse($bus->isRunning());

        // Calling stop() again should be idempotent
        $bus->stop();
        $this->assertFalse($bus->isRunning());
    }
}
