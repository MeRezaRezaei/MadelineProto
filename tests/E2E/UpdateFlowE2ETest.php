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

use Amp\PHPUnit\AsyncTestCase;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;

class UpdateFlowE2ETest extends AsyncTestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    public function testUpdateFlowsStoreCacheAndBus(): void
    {
        $this->setTimeout(10);
        try {
            $raw = \Amp\Redis\createRedisClient(\Amp\Redis\RedisConfig::fromUri(self::DSN));
            $raw->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis unreachable at ' . self::DSN . ': ' . $e->getMessage());
        }

        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $cache = new Cache(self::DSN);
        $targets = new SyncTargets($driver);
        $targets->add(100, 'channel');

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $handler = new UpdateHandler($store, $cache, $bus, $targets);

        $got = new \Amp\DeferredFuture();
        $bus->on('updateNewMessage', static function (int $accountId, string $type, array $data) use ($got): void {
            if (!$got->isComplete()) {
                $got->complete($data);
            }
        });
        $bus->start();

        \Amp\delay(0.1);

        $handler->process(42, 'updateNewMessage', [
            'peer_id' => 100,
            'id' => 77,
            'message' => 'e2e',
            'date' => 1700000000,
            'raw' => '{"id":77}',
        ]);

        $data = $got->getFuture()->await();
        $this->assertSame(77, $data['id']);
        $this->assertNotNull($store->getMessage(100, 77));

        $bus->stop();
    }
}
