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

use danog\MadelineProto\API;
use danog\MadelineProto\Db\RelationalStore;

final class BackfillWorker
{
    /** @var callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int, message?:string, raw?:string}> */
    private $fetchPage;

    /**
     * @param callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int, message?:string, raw?:string}> $fetchPage
     */
    public function __construct(
        private RelationalStore $store,
        private FetchQueue $queue,
        callable $fetchPage,
        private int $pageSize = 100,
    ) {
        $this->fetchPage = $fetchPage;
    }

    /**
     * Factory for creating a live MTProto getHistory fetcher from a MadelineProto API instance.
     *
     * @return callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int, message?:string, raw?:string}>
     */
    public static function getHistoryFetcher(API $api): callable
    {
        return static function (int $peerId, int $offset, int $limit) use ($api): array {
            $res = $api->messages->getHistory([
                'peer' => $peerId,
                'offset_id' => $offset,
                'limit' => $limit,
            ]);

            $out = [];
            foreach ($res['messages'] ?? [] as $msg) {
                if (!isset($msg['id'])) {
                    continue;
                }
                $out[] = [
                    'peer_id' => $peerId,
                    'id' => (int) $msg['id'],
                    'from_id' => isset($msg['from_id']) ? (int) $msg['from_id'] : null,
                    'date' => isset($msg['date']) ? (int) $msg['date'] : null,
                    'message' => $msg['message'] ?? null,
                    'media' => isset($msg['media']) ? json_encode($msg['media']) : null,
                    'entities' => isset($msg['entities']) ? json_encode($msg['entities']) : null,
                    'raw' => json_encode($msg),
                ];
            }

            return $out;
        };
    }

    public function run(int $quotaRemaining, int $costPerPage = 10): void
    {
        $pagesLeft = FetchQueue::quotaSlice($quotaRemaining, $costPerPage);

        while ($pagesLeft > 0 && ($job = $this->queue->claim()) !== null) {
            try {
                $offset = 0;
                for ($p = 0; $p < $pagesLeft; $p++) {
                    $page = ($this->fetchPage)($job['peer_id'], $offset, $this->pageSize);
                    if ($page === []) {
                        break 2; // history exhausted
                    }
                    foreach ($page as $msg) {
                        if ($job['until_date'] !== null
                            && isset($msg['date'])
                            && (int) $msg['date'] < $job['until_date']) {
                            break 3; // past boundary — job done
                        }
                        $this->store->upsertMessage($msg + ['deleted_at' => null]);
                    }
                    $offset += $this->pageSize;
                    $pagesLeft--;
                }
                $this->queue->complete($job['id']);
            } catch (\Throwable) {
                $this->queue->fail($job['id']);

                return; // gradual: give quota back to live traffic this pass
            }
        }
    }
}
