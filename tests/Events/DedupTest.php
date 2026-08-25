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

/**
 * Cross-account deduplication acceptance tests (Redis on tcp://127.0.0.1:16379).
 */
class DedupTest extends AsyncTestCase
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

        $this->prefix = 'mp-dedup-' . bin2hex(random_bytes(4)) . ':';
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
        }

        parent::tearDown();
    }

    /**
     * Three accounts emit the same msg:100:42 → exactly ONE delivery.
     */
    public function testThreeAccountsSameUpdateOneDelivery(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, ['messages' => 3600, 'service' => 300]);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = $accountId;
            $deferred->complete();
        });

        $bus->start();

        $data = ['peer_id' => 100, 'message_id' => 42, 'message' => 'test'];
        $bus->emit(1, 'updateNewMessage', $data);
        $bus->emit(2, 'updateNewMessage', $data);
        $bus->emit(3, 'updateNewMessage', $data);

        $deferred->getFuture()->await();

        $this->assertCount(1, $received, 'Same update from 3 accounts must be delivered exactly once');
        $this->assertSame(1, $received[0], 'First account should be the one that actually delivered');

        $bus->stop();
    }

    /**
     * After the first delivery, a fourth account emitting the same key → NO delivery.
     */
    public function testFourthAccountSameUpdateNoDelivery(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $deferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, ['messages' => 3600, 'service' => 300]);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred): void {
            $received[] = $accountId;
            $deferred->complete();
        });

        $bus->start();

        $data = ['peer_id' => 100, 'message_id' => 42, 'message' => 'test'];
        $bus->emit(1, 'updateNewMessage', $data);

        $deferred->getFuture()->await();

        $this->assertCount(1, $received);

        // Fourth account, same key — no new delivery expected.
        $bus->emit(4, 'updateNewMessage', $data);

        // Give a short window for any spurious delivery.
        usleep(200_000);

        $this->assertCount(1, $received, 'Fourth account emitting same key must not trigger delivery');

        $bus->stop();
    }

    /**
     * Different (peer_id, message_id) → NOT deduped.
     */
    public function testDifferentKeyNotDeduped(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $deferred = new DeferredFuture;
        $expectedCount = 2;

        $bus = new EventBus(self::DSN, self::DSN, ['messages' => 3600, 'service' => 300]);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $deferred, $expectedCount): void {
            $received[] = ['account_id' => $accountId, 'data' => $data];
            if (\count($received) >= $expectedCount) {
                $deferred->complete();
            }
        });

        $bus->start();

        $bus->emit(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 42]);
        $bus->emit(2, 'updateNewMessage', ['peer_id' => 200, 'message_id' => 99]);

        $deferred->getFuture()->await();

        $this->assertCount(2, $received, 'Different keys must each be delivered');
        $this->assertSame(1, $received[0]['account_id']);
        $this->assertSame(2, $received[1]['account_id']);

        $bus->stop();
    }

    /**
     * Dedup TTL: after the Redis key expires (2 s), the same update is delivered again.
     */
    public function testDedupTtlExpiryAllowsRedelivery(): void
    {
        $this->setTimeout(20.0);

        $received = [];
        $firstDeferred = new DeferredFuture;
        $secondDeferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, ['messages' => 2, 'service' => 2]);
        $bus->on('updateNewMessage', function (int $accountId, string $type, array $data) use (&$received, $firstDeferred, $secondDeferred): void {
            $received[] = $accountId;
            if (\count($received) === 1) {
                $firstDeferred->complete();
            } elseif (\count($received) === 2) {
                $secondDeferred->complete();
            }
        });

        $bus->start();

        $data = ['peer_id' => 100, 'message_id' => 42];

        // First emit — should deliver (2 s TTL configured via constructor).
        $bus->emit(1, 'updateNewMessage', $data);
        $firstDeferred->getFuture()->await();
        $this->assertCount(1, $received);

        // Same key, same emit — deduped (in-memory + Redis).
        $bus->emit(1, 'updateNewMessage', $data);
        usleep(200_000);
        $this->assertCount(1, $received, 'Same key before TTL expiry must not deliver');

        // Wait for Redis key to expire (2 s TTL + margin).
        \Amp\delay(3.0);

        // After expiry, same key should be delivered again.
        $bus->emit(1, 'updateNewMessage', $data);
        $secondDeferred->getFuture()->await();
        $this->assertCount(2, $received, 'After TTL expiry, same update must be delivered again');

        $bus->stop();
    }

    /**
     * isDuplicate is idempotent: calling twice with same key → only one Redis SET.
     */
    public function testIsDuplicateIdempotent(): void
    {
        $this->setTimeout(5.0);

        $bus = new EventBus(self::DSN, self::DSN);
        $key = 'msg:100:42';

        $first = $bus->isDuplicate($key, 300);
        $this->assertFalse($first, 'First call must return false (not duplicate)');

        $second = $bus->isDuplicate($key, 300);
        $this->assertTrue($second, 'Second call must return true (duplicate)');
    }

    /**
     * computeDedupKey produces the correct keys for each update shape.
     */
    public function testComputeDedupKey(): void
    {
        $this->assertSame(
            'msg:100:42',
            EventBus::computeDedupKey(1, 'updateNewMessage', ['peer_id' => 100, 'message_id' => 42]),
        );
        $this->assertSame(
            'pts:5:1234',
            EventBus::computeDedupKey(5, 'updateChatParticipant', ['pts' => 1234]),
        );
        $this->assertSame(
            'update:3:77',
            EventBus::computeDedupKey(3, 'updateOtherStuff', ['update_id' => 77]),
        );
        $this->assertSame(
            'update:3:updateOtherStuff',
            EventBus::computeDedupKey(3, 'updateOtherStuff', []),
        );
    }

    /**
     * Service update (pts-based) dedups across accounts using per-account key.
     */
    public function testServiceUpdateDedupByPts(): void
    {
        $this->setTimeout(10.0);

        $received = [];
        $firstDeferred = new DeferredFuture;
        $secondDeferred = new DeferredFuture;

        $bus = new EventBus(self::DSN, self::DSN, ['messages' => 3600, 'service' => 300]);
        $bus->on('updateChatParticipant', function (int $accountId, string $type, array $data) use (&$received, $firstDeferred, $secondDeferred): void {
            $received[] = $accountId;
            if (\count($received) === 1) {
                $firstDeferred->complete();
            } elseif (\count($received) === 2) {
                $secondDeferred->complete();
            }
        });

        $bus->start();

        // Service update with pts — per-account key, so each account is distinct.
        $bus->emit(1, 'updateChatParticipant', ['pts' => 500, 'user_id' => 10]);
        $firstDeferred->getFuture()->await();
        $this->assertCount(1, $received);

        // Same account, same pts → deduped.
        $bus->emit(1, 'updateChatParticipant', ['pts' => 500, 'user_id' => 10]);
        usleep(200_000);
        $this->assertCount(1, $received, 'Same account + pts must be deduped');

        // Different account, same pts → NOT deduped (per-account key).
        $bus->emit(2, 'updateChatParticipant', ['pts' => 500, 'user_id' => 10]);
        $secondDeferred->getFuture()->await();
        $this->assertCount(2, $received, 'Different account with same pts must not be deduped');

        $bus->stop();
    }
}
