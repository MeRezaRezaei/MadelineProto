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

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Sync\BackfillWorker;
use danog\MadelineProto\Sync\FetchQueue;
use PHPUnit\Framework\TestCase;

class BackfillWorkerTest extends TestCase
{
    private RelationalStore $store;
    private FetchQueue $queue;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
        $this->queue = new FetchQueue($driver);
    }

    public function testDrainsJobStoringPagesUntilBoundary(): void
    {
        // Fake history: messages id 1..30, date ascending with id; boundary until_date = 1700000000.
        // Cursor semantics matching getHistory(offset_id, add_offset=0): cursor 0 = newest;
        // otherwise return ids cursor..cursor-limit+1 descending, clamped at id 1.
        $fetcher = static function (int $peerId, int $cursor, int $limit): array {
            $top = $cursor === 0 ? 30 : $cursor - 1;
            $out = [];
            for ($i = 0; $i < $limit && $top - $i >= 1; $i++) {
                $id = $top - $i;
                $out[] = ['peer_id' => $peerId, 'id' => $id, 'date' => 1699999800 + $id * 10,
                          'message' => 'm' . $id, 'raw' => null];
            }

            return $out;
        };

        $this->queue->enqueue(100, 1700000000);

        $worker = new BackfillWorker($this->store, $this->queue, $fetcher, pageSize: 10);
        $worker->run(quotaRemaining: 100, costPerPage: 10);   // slice = 5 pages

        // 30 messages exist; boundary at 1700000000 → ids with date >= boundary stored:
        // dates 1700000100-10i >= 1700000000 → i <= 10 → ids 20..30 = 11 messages
        $this->assertNotNull($this->store->getMessage(100, 30));
        $this->assertNotNull($this->store->getMessage(100, 20));
        $this->assertNull($this->store->getMessage(100, 19));
        $this->assertNull($this->queue->claim(), 'job completed');
    }

    public function testTinyQuotaDoesNothingButKeepsJob(): void
    {
        $fetcher = static fn (): array => [];
        $this->queue->enqueue(100, null);

        (new BackfillWorker($this->store, $this->queue, $fetcher, pageSize: 10))
            ->run(quotaRemaining: 5, costPerPage: 10);        // slice = 0

        $this->assertNotNull($this->queue->claim(), 'job must stay queued');
    }

    public function testSliceExhaustionKeepsJobPendingWithCursorAndLaterResumes(): void
    {
        // 500 messages, no boundary: full drain needs 50 pages of 10.
        $fetcher = static function (int $peerId, int $cursor, int $limit): array {
            $top = $cursor === 0 ? 500 : $cursor - 1;
            $out = [];
            for ($i = 0; $i < $limit && $top - $i >= 1; $i++) {
                $id = $top - $i;
                $out[] = ['peer_id' => $peerId, 'id' => $id, 'date' => 1700000000 + $id,
                          'message' => 'm' . $id, 'raw' => null];
            }

            return $out;
        };

        $this->queue->enqueue(100, null);

        $worker = new BackfillWorker($this->store, $this->queue, $fetcher, pageSize: 10);
        $worker->run(quotaRemaining: 100, costPerPage: 10);   // slice = 5 pages → 50 msgs, no empty page

        // Job must still be claimable, with its cursor past the pages consumed…
        $job = $this->queue->claim();
        $this->assertNotNull($job, 'job stays pending after slice exhaustion');
        $this->assertSame(451, $job['cursor_id']);
        $this->assertNotNull($this->store->getMessage(100, 500));
        $this->assertNotNull($this->store->getMessage(100, 451));
        $this->assertNull($this->store->getMessage(100, 450)); // not yet fetched
        $this->queue->requeue($job['id']);                     // hand it back for the resume pass

        // …and a later pass with bigger quota drains the rest and completes it.
        $worker->run(quotaRemaining: 2000, costPerPage: 10);  // slice = 100 pages
        $this->assertNull($this->queue->claim(), 'job completed on resume');
        $this->assertNotNull($this->store->getMessage(100, 450));
        $this->assertNotNull($this->store->getMessage(100, 1));
    }
}
