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

use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;
use PHPUnit\Framework\TestCase;

class UpdateHandlerTest extends TestCase
{
    private UpdateHandler $handler;
    private RelationalStore $store;
    private SyncTargets $targets;
    /** @var list<array{int, string, array}> */
    private array $emitted = [];

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
        $this->targets = new SyncTargets($driver);
        $this->targets->add(100, 'channel');

        // EventBus spy: record emit calls, no network. No parent call — the
        // Redis connections are lazy and every method we use is overridden.
        $bus = new class($this->emitted) extends EventBus {
            public function __construct(private array &$emitted)
            {
            }
            public function emit(int $accountId, string $type, array $data): void
            {
                $this->emitted[] = [$accountId, $type, $data];
            }
        };

        $cache = new class extends Cache {
            public function __construct()
            {
            }
            public function delete(string ...$keys): \Amp\Future
            {
                return \Amp\async(static fn (): null => null);
            }
        };

        $this->handler = new UpdateHandler($this->store, $cache, $bus, $this->targets);
    }

    public function testNewMessageUpsertedEmittedAndCached(): void
    {
        $this->handler->process(7, 'updateNewMessage', [
            'peer_id' => 100, 'id' => 1, 'message' => 'hi',
            'date' => 1700000000, 'raw' => '{"id":1}',
        ]);

        $msg = $this->store->getMessage(100, 1);
        $this->assertNotNull($msg);
        $this->assertSame('hi', $msg['message']);
        $this->assertNull($msg['deleted_at']);

        $this->assertCount(1, $this->emitted);
        [$accountId, $type, $data] = $this->emitted[0];
        $this->assertSame(7, $accountId);
        $this->assertSame('updateNewMessage', $type);
        $this->assertSame(1, $data['id']);
    }

    public function testNonTargetPeerIsStoredNowhereButStillEmitted(): void
    {
        $this->handler->process(7, 'updateNewMessage', ['peer_id' => 999, 'id' => 1, 'message' => 'x']);
        $this->assertNull($this->store->getMessage(999, 1));
        $this->assertCount(1, $this->emitted);
    }

    public function testDeleteMessagesSoftDeletesNeverRemoves(): void
    {
        $this->handler->process(7, 'updateNewMessage', ['peer_id' => 100, 'id' => 5, 'message' => 'x']);
        $this->handler->process(7, 'updateDeleteMessages', ['peer_id' => 100, 'ids' => [5]]);

        $msg = $this->store->getMessage(100, 5);
        $this->assertNotNull($msg, 'row must survive Telegram-side deletion');
        $this->assertNotNull($msg['deleted_at']);
        $this->assertCount(2, $this->emitted);
    }
}
