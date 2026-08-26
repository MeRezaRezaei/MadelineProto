<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    The MadelineProto Team
 * @copyright 2016-2025 The MadelineProto Team
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\SqlDriver;

final class BackupStore
{
    private SqlDriver $driver;

    public function __construct(SqlDriver $driver)
    {
        $this->driver = $driver;
    }

    public function getDriver(): SqlDriver
    {
        return $this->driver;
    }

    /**
     * Idempotent upsert over an explicit primary key (copied from RelationalStore).
     *
     * @param array<string, mixed> $data Column => value map
     * @param array<int, string>  $pk   Primary key columns
     */
    private function upsert(string $table, array $data, array $pk): void
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $placeholders) . ') ';

        if ($this->driver->getDialect() === 'pgsql') {
            $sql .= 'ON CONFLICT (' . implode(', ', $pk) . ') DO UPDATE SET ';
        } else {
            $sql .= 'ON CONFLICT(' . implode(', ', $pk) . ') DO UPDATE SET ';
        }

        $updates = [];
        foreach ($columns as $col) {
            if (!in_array($col, $pk, true)) {
                $updates[] = $col . ' = excluded.' . $col;
            }
        }
        $sql .= implode(', ', $updates);

        $this->driver->exec($sql, array_values($data));
    }

    public function setChannel(string $setId, int $channelId, string $saltHex): void
    {
        $this->upsert('backup_sets', [
            'set_id' => $setId,
            'channel_id' => $channelId,
            'salt_hex' => $saltHex,
        ], ['set_id']);
    }

    public function getChannel(string $setId): ?int
    {
        $rows = $this->driver->query('SELECT channel_id FROM backup_sets WHERE set_id = ?', [$setId]);
        return isset($rows[0]) ? (int) $rows[0]['channel_id'] : null;
    }

    public function getSalt(string $setId): ?string
    {
        $rows = $this->driver->query('SELECT salt_hex FROM backup_sets WHERE set_id = ?', [$setId]);
        return isset($rows[0]) ? (string) $rows[0]['salt_hex'] : null;
    }

    public function recordChunk(string $hash, string $setId, int $msgId, string $fileId, int $size): void
    {
        $this->upsert('chunks', [
            'hash' => $hash,
            'set_id' => $setId,
            'msg_id' => $msgId,
            'file_id' => $fileId,
            'size' => $size,
        ], ['hash']);
    }

    public function findChunk(string $hash): ?array
    {
        $rows = $this->driver->query('SELECT * FROM chunks WHERE hash = ?', [$hash]);
        if (!isset($rows[0])) {
            return null;
        }
        $rows[0]['msg_id'] = (int) $rows[0]['msg_id'];
        $rows[0]['size'] = (int) $rows[0]['size'];
        return $rows[0];
    }

    /**
     * @param array<int, array{path: string, size: int, mtime: int, sha256: string, chunks_json: string}> $files
     */
    public function recordSnapshot(string $snapshotId, string $setId, int $manifestMsgId, array $files, int $totalBytes): void
    {
        $this->upsert('snapshots', [
            'snapshot_id' => $snapshotId,
            'set_id' => $setId,
            'manifest_msg_id' => $manifestMsgId,
            'file_count' => count($files),
            'total_bytes' => $totalBytes,
        ], ['snapshot_id']);

        $this->driver->exec('DELETE FROM backup_files WHERE snapshot_id = ?', [$snapshotId]);
        foreach ($files as $file) {
            $this->driver->exec(
                'INSERT INTO backup_files (snapshot_id, path, size, mtime, sha256, chunks_json) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $snapshotId,
                    $file['path'],
                    $file['size'],
                    $file['mtime'],
                    $file['sha256'],
                    $file['chunks_json'],
                ]
            );
        }
    }

    public function latestSnapshot(string $setId): ?array
    {
        $rows = $this->driver->query(
            'SELECT * FROM snapshots WHERE set_id = ? ORDER BY created_at DESC, snapshot_id DESC LIMIT 1',
            [$setId]
        );
        if (!isset($rows[0])) {
            return null;
        }
        $rows[0]['manifest_msg_id'] = (int) $rows[0]['manifest_msg_id'];
        $rows[0]['file_count'] = (int) $rows[0]['file_count'];
        $rows[0]['total_bytes'] = (int) $rows[0]['total_bytes'];
        return $rows[0];
    }

    /** @return array<int, array<string, mixed>> */
    public function snapshotFiles(string $snapshotId): array
    {
        return $this->driver->query(
            'SELECT path, size, mtime, sha256, chunks_json FROM backup_files WHERE snapshot_id = ? ORDER BY path ASC',
            [$snapshotId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listSnapshots(string $setId): array
    {
        return $this->driver->query(
            'SELECT * FROM snapshots WHERE set_id = ? ORDER BY created_at DESC, snapshot_id DESC',
            [$setId]
        );
    }

    /** @return list<string> */
    public function randomChunkHashes(int $limit): array
    {
        $rows = $this->driver->query('SELECT hash FROM chunks ORDER BY RANDOM() LIMIT ?', [$limit]);
        return array_column($rows, 'hash');
    }
}
