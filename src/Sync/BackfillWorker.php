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

namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\RelationalStore;

final class BackfillWorker
{
    /**
     * @param callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int, message?:string, raw?:string}> $fetchPage
     */
    public function __construct(
        private RelationalStore $store,
        private FetchQueue $queue,
        private $fetchPage,
        private int $pageSize = 100,
    ) {
    }

    public function run(int $quotaRemaining, int $costPerPage = 10): void
    {
        $pagesLeft = FetchQueue::quotaSlice($quotaRemaining, $costPerPage);

        while ($pagesLeft > 0 && ($job = $this->queue->claim()) !== null) {
            try {
                $offset = $job['cursor_id']; // opaque id-cursor: 0 = start from newest; otherwise min id of last page
                $done = false;
                $slice = $pagesLeft;
                for ($p = 0; $p < $slice; $p++) {
                    $page = ($this->fetchPage)($job['peer_id'], $offset, $this->pageSize);
                    $pagesLeft--; // every fetched page counts against quota, incl. the terminal one
                    if ($page === []) {
                        $done = true; // history exhausted — job done
                        break;
                    }
                    $offset = (int) min(array_column($page, 'id')); // pages are id-descending
                    $this->queue->saveCursor($job['id'], $offset); // persist progress BEFORE rows, so a crash resumes here
                    foreach ($page as $msg) {
                        if ($job['until_date'] !== null
                            && isset($msg['date'])
                            && (int) $msg['date'] < $job['until_date']) {
                            $done = true; // past boundary — job done
                            break 2;
                        }
                        $this->store->upsertMessage($msg + ['deleted_at' => null]);
                    }
                }
                if ($done) {
                    $this->queue->complete($job['id']);
                } else {
                    // Slice exhausted mid-history: keep the job pending with its
                    // cursor so the next pass resumes instead of silently truncating.
                    $this->queue->requeue($job['id']);
                }
            } catch (\Throwable) {
                $this->queue->fail($job['id']);

                return; // gradual: give quota back to live traffic this pass
            }
        }
    }

    /**
     * Real fetcher wrapping MadelineProto messages->getHistory. Never called
     * by the offline tests.
     *
     * @return callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int|null, message?:string|null, raw?:string}>
     */
    public static function getHistoryFetcher(\danog\MadelineProto\API $api): callable
    {
        return static function (int $peerId, int $offset, int $limit) use ($api): array {
            $result = $api->messages->getHistory(['peer' => $peerId, 'offset_id' => $offset, 'add_offset' => 0, 'limit' => $limit]);
            $rows = [];
            foreach ($result['messages'] ?? [] as $msg) {
                $rows[] = [
                    'peer_id' => $peerId,
                    'id' => (int) $msg['id'],
                    'date' => isset($msg['date']) ? (int) $msg['date'] : null,
                    'message' => $msg['message'] ?? null,
                    'raw' => json_encode($msg),
                ];
            }

            return $rows;
        };
    }
}
