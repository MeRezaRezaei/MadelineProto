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

final class FetchQueue
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private SqlDriver $driver)
    {
    }

    /** Max fetches runnable now while reserving >= 50% of remaining quota headroom. */
    public static function quotaSlice(int $remaining, int $costPerFetch): int
    {
        if ($costPerFetch <= 0 || $remaining <= 0) {
            return 0;
        }

        return intdiv(intdiv($remaining, 2), $costPerFetch);
    }

    public function enqueue(int $peerId, ?int $untilDateEpoch): void
    {
        $this->driver->exec(
            'INSERT INTO fetch_jobs (peer_id, until_date, attempts, status) VALUES (?, ?, 0, ?)',
            [$peerId, $untilDateEpoch, 'pending'],
        );
    }

    /** @return array{id: int, peer_id: int, until_date: ?int, cursor_id: int}|null */
    public function claim(): ?array
    {
        $rows = $this->driver->query(
            "SELECT * FROM fetch_jobs WHERE status = 'pending' ORDER BY id LIMIT 1",
        );
        if (!isset($rows[0])) {
            return null;
        }

        $this->driver->exec("UPDATE fetch_jobs SET status = 'running' WHERE id = ?", [$rows[0]['id']]);

        return [
            'id' => (int) $rows[0]['id'],
            'peer_id' => (int) $rows[0]['peer_id'],
            'until_date' => $rows[0]['until_date'] === null ? null : (int) $rows[0]['until_date'],
            'cursor_id' => (int) $rows[0]['cursor_id'],
        ];
    }

    /** Persist backfill progress so an interrupted job resumes where it stopped. */
    public function saveCursor(int $id, int $cursorId): void
    {
        $this->driver->exec('UPDATE fetch_jobs SET cursor_id = ? WHERE id = ?', [$cursorId, $id]);
    }

    /** Return a job to the pending pool without counting an attempt (quota slice exhausted). */
    public function requeue(int $id): void
    {
        $this->driver->exec("UPDATE fetch_jobs SET status = 'pending' WHERE id = ?", [$id]);
    }

    public function complete(int $id): void
    {
        $this->driver->exec('DELETE FROM fetch_jobs WHERE id = ?', [$id]);
    }

    public function fail(int $id): void
    {
        $rows = $this->driver->query('SELECT attempts FROM fetch_jobs WHERE id = ?', [$id]);
        if (!isset($rows[0])) {
            return;
        }
        $attempts = (int) $rows[0]['attempts'] + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? 'dead' : 'pending';
        $this->driver->exec(
            'UPDATE fetch_jobs SET attempts = ?, status = ? WHERE id = ?',
            [$attempts, $status, $id],
        );
    }

    public function deadLetterCount(): int
    {
        $rows = $this->driver->query("SELECT COUNT(*) AS c FROM fetch_jobs WHERE status = 'dead'");

        return (int) ($rows[0]['c'] ?? 0);
    }
}
