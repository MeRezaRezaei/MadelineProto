<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
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
use Amp\Redis\RedisClient;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;
use function Amp\Redis\createRedisClient;
use Amp\Redis\RedisConfig;

/**
 * E2E: a live update flows through UpdateHandler into the relational store
 * and out over the Redis EventBus (hot path).
 *
 * sqlite::memory: for the store, shared test Redis on tcp://127.0.0.1:16379
 * for cache + bus; self-skips when Redis is unreachable.
 */
class UpdateFlowE2ETest extends AsyncTestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    private ?RedisClient $raw = null;

    protected function setUp(): void
    {
        parent::setUp();

        $socket = @fsockopen('127.0.0.1', 16379, $errno, $errstr, 2);
        if ($socket === false) {
            $this->markTestSkipped('Redis is not reachable at ' . self::DSN . ': ' . $errstr);
        }
        fclose($socket);

        $this->raw = createRedisClient(RedisConfig::fromUri(self::DSN));
        $this->cleanDedupKeys();
    }

    protected function tearDown(): void
    {
        if ($this->raw !== null) {
            $this->cleanDedupKeys();
        }

        parent::tearDown();
    }

    /**
     * EventBus::emit dedups via SETNX keys with a TTL; a leftover key from a
     * previous run within the TTL window would swallow our only emit.
     */
    private function cleanDedupKeys(): void
    {
        $keys = $this->raw->execute('keys', 'madeline:dedup:*');
        if (\is_array($keys)) {
            foreach ($keys as $key) {
                $this->raw->execute('del', $key);
            }
        }
    }

    public function testUpdateFlowsStoreCacheAndBus(): void
    {
        $this->setTimeout(10);
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $cache = new Cache(self::DSN);
        $targets = new SyncTargets($driver);
        $targets->add(100, 'channel');

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $handler = new UpdateHandler($store, $cache, $bus, $targets);

        $got = new DeferredFuture;
        $bus->on('updateNewMessage', static function (int $accountId, string $type, array $data) use ($got): void {
            $got->complete($data);
        });
        $bus->start();

        $handler->process(42, 'updateNewMessage', [
            'peer_id' => 100, 'id' => 77, 'message' => 'e2e', 'date' => 1700000000, 'raw' => '{"id":77}',
        ]);

        $data = $got->getFuture()->await();
        $this->assertSame(77, $data['id']);
        $this->assertNotNull($store->getMessage(100, 77));

        $bus->stop();
    }
}
