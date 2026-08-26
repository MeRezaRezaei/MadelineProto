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

use danog\MadelineProto\Db\SqlDriver;

final class SyncTargets
{
    public function __construct(private SqlDriver $driver)
    {
    }

    public function add(int $peerId, string $type, ?int $historySinceEpoch = null): void
    {
        $this->driver->exec(
            'INSERT INTO sync_targets (peer_id, type, history_since, enabled) VALUES (?, ?, ?, 1)
             ON CONFLICT(peer_id) DO UPDATE SET type = excluded.type, history_since = excluded.history_since, enabled = 1',
            [$peerId, $type, $historySinceEpoch],
        );
    }

    public function remove(int $peerId): void
    {
        $this->driver->exec('DELETE FROM sync_targets WHERE peer_id = ?', [$peerId]);
    }

    public function setEnabled(int $peerId, bool $enabled): void
    {
        $this->driver->exec('UPDATE sync_targets SET enabled = ? WHERE peer_id = ?', [(int) $enabled, $peerId]);
    }

    public function isTarget(int $peerId): bool
    {
        $rows = $this->driver->query('SELECT 1 FROM sync_targets WHERE peer_id = ? AND enabled = 1', [$peerId]);

        return isset($rows[0]);
    }

    public function historySince(int $peerId): ?int
    {
        $rows = $this->driver->query('SELECT history_since FROM sync_targets WHERE peer_id = ?', [$peerId]);

        return isset($rows[0]) ? ($rows[0]['history_since'] === null ? null : (int) $rows[0]['history_since']) : null;
    }

    /** @return array<int, array{peer_id: int, type: string, history_since: ?int}> */
    public function listEnabled(): array
    {
        return array_map(
            static fn (array $r): array => [
                'peer_id' => (int) $r['peer_id'],
                'type' => (string) $r['type'],
                'history_since' => $r['history_since'] === null ? null : (int) $r['history_since'],
            ],
            $this->driver->query('SELECT * FROM sync_targets WHERE enabled = 1 ORDER BY peer_id'),
        );
    }
}
