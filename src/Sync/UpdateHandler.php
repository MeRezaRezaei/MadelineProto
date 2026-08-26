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

namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;

final class UpdateHandler
{
    public function __construct(
        private RelationalStore $store,
        private Cache $cache,
        private EventBus $bus,
        private SyncTargets $targets,
    ) {
    }

    public function process(int $accountId, string $type, array $data): void
    {
        if ($type === 'updateNewMessage' || $type === 'updateEditMessage') {
            if (isset($data['peer_id'], $data['id']) && $this->targets->isTarget((int) $data['peer_id'])) {
                $this->store->upsertMessage($data + ['deleted_at' => null]);
                $this->cache->delete(Cache::messageKey((int) $data['peer_id'], (int) $data['id']))->await();
            }
        } elseif ($type === 'updateDeleteMessages' && isset($data['peer_id'], $data['ids'])) {
            foreach ($data['ids'] as $mid) {
                $row = $this->store->getMessage((int) $data['peer_id'], (int) $mid);
                if ($row !== null && $row['deleted_at'] === null) {
                    // $row comes from getMessage (SELECT *), so it already
                    // carries deleted_at = null; overwrite, don't union (+).
                    $row['deleted_at'] = time();
                    $this->store->upsertMessage($row);
                    $this->cache->delete(Cache::messageKey((int) $data['peer_id'], (int) $mid))->await();
                }
            }
        }

        $this->bus->emit($accountId, $type, $data);
    }
}
