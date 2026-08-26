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
use danog\MadelineProto\Sync\FetchQueue;
use PHPUnit\Framework\TestCase;

class FetchQueueTest extends TestCase
{
    private FetchQueue $queue;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->queue = new FetchQueue($driver);
    }

    public function testEnqueueClaimCompleteLifecycle(): void
    {
        $this->queue->enqueue(100, 1700000000);
        $this->queue->enqueue(200, null);

        $job = $this->queue->claim();
        $this->assertSame(100, $job['peer_id']);          // FIFO
        $this->assertSame(1700000000, $job['until_date']);

        // claimed job is not handed out twice
        $this->assertSame(200, $this->queue->claim()['peer_id']);
        $this->assertNull($this->queue->claim());

        $this->queue->complete($job['id']);
        $this->assertSame(0, $this->queue->deadLetterCount());
    }

    public function testFailRetriesThenDeadLetters(): void
    {
        $this->queue->enqueue(300, null);
        $job = $this->queue->claim();

        for ($i = 0; $i < 4; $i++) {
            $this->queue->fail($job['id']);
            $again = $this->queue->claim();               // requeued while attempts < 5
            $this->assertSame(300, $again['peer_id']);
            $job = $again;
        }

        $this->queue->fail($job['id']);                   // 5th failure → dead
        $this->assertNull($this->queue->claim());
        $this->assertSame(1, $this->queue->deadLetterCount());
    }

    public function testQuotaSliceReservesHalf(): void
    {
        // 100 remaining, each fetch costs 10 → may use at most 50 → 5 fetches
        $this->assertSame(5, FetchQueue::quotaSlice(100, 10));
        // tiny budget → zero fetches, all headroom kept
        $this->assertSame(0, FetchQueue::quotaSlice(9, 10));
        $this->assertSame(0, FetchQueue::quotaSlice(0, 1));
    }
}
