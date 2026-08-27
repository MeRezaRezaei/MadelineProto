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
        $isPgsql = $this->driver->getDialect() === 'pgsql';
        $enabledVal = $isPgsql ? true : 1;
        $historySinceVal = $historySinceEpoch !== null ? ($isPgsql ? date('c', $historySinceEpoch) : $historySinceEpoch) : null;
        $conflictClause = $isPgsql ? 'ON CONFLICT (peer_id)' : 'ON CONFLICT(peer_id)';
        $this->driver->exec(
            "INSERT INTO sync_targets (peer_id, type, history_since, enabled) VALUES (?, ?, ?, ?)
             {$conflictClause} DO UPDATE SET type = excluded.type, history_since = excluded.history_since, enabled = excluded.enabled",
            [$peerId, $type, $historySinceVal, $enabledVal],
        );
    }

    public function remove(int $peerId): void
    {
        $this->driver->exec('DELETE FROM sync_targets WHERE peer_id = ?', [$peerId]);
    }

    public function setEnabled(int $peerId, bool $enabled): void
    {
        $isPgsql = $this->driver->getDialect() === 'pgsql';
        $enabledVal = $isPgsql ? $enabled : (int) $enabled;
        $this->driver->exec('UPDATE sync_targets SET enabled = ? WHERE peer_id = ?', [$enabledVal, $peerId]);
    }

    public function isTarget(int $peerId): bool
    {
        $isPgsql = $this->driver->getDialect() === 'pgsql';
        $enabledVal = $isPgsql ? true : 1;
        $rows = $this->driver->query('SELECT 1 FROM sync_targets WHERE peer_id = ? AND enabled = ?', [$peerId, $enabledVal]);

        return isset($rows[0]);
    }

    public function historySince(int $peerId): ?int
    {
        $rows = $this->driver->query('SELECT history_since FROM sync_targets WHERE peer_id = ?', [$peerId]);
        if (!isset($rows[0]) || $rows[0]['history_since'] === null) {
            return null;
        }

        $val = $rows[0]['history_since'];
        return is_numeric($val) ? (int) $val : (int) strtotime((string) $val);
    }

    /** @return array<int, array{peer_id: int, type: string, history_since: ?int}> */
    public function listEnabled(): array
    {
        $isPgsql = $this->driver->getDialect() === 'pgsql';
        $enabledVal = $isPgsql ? true : 1;
        return array_map(
            static function (array $r): array {
                $hs = $r['history_since'] ?? null;
                $epoch = $hs === null ? null : (is_numeric($hs) ? (int) $hs : (int) strtotime((string) $hs));
                return [
                    'peer_id' => (int) $r['peer_id'],
                    'type' => (string) $r['type'],
                    'history_since' => $epoch,
                ];
            },
            $this->driver->query('SELECT * FROM sync_targets WHERE enabled = ? ORDER BY peer_id', [$enabledVal]),
        );
    }
}
