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

namespace danog\MadelineProto\Db;

/**
 * Single source of truth for Telegram data.
 *
 * Every id is supplied explicitly (never auto-generated). The verbatim Telegram
 * object is preserved under the `raw` column exactly as received. Upserts are
 * idempotent (INSERT ... ON CONFLICT DO UPDATE on the primary key).
 */
class RelationalStore
{
    private SqlDriver $driver;

    public function __construct(SqlDriver $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Idempotent upsert over an explicit primary key.
     *
     * @param array<string, mixed> $data Column => value map
     * @param array<int, string>   $pk   Primary key columns
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

    // ---------------------------------------------------------------------
    // accounts
    // ---------------------------------------------------------------------

    public function upsertAccount(int $id, int $apiId, string $apiHash, ?string $authState, ?string $sessionBlob = null): void
    {
        $this->upsert('accounts', [
            'id' => $id,
            'api_id' => $apiId,
            'api_hash' => $apiHash,
            'auth_state' => $authState,
            'session_blob' => $sessionBlob,
        ], ['id']);
    }

    public function getAccount(int $id): ?array
    {
        $rows = $this->driver->query('SELECT * FROM accounts WHERE id = ?', [$id]);

        return isset($rows[0]) ? $rows[0] : null;
    }

    public function listAccounts(): array
    {
        return $this->driver->query('SELECT * FROM accounts ORDER BY id');
    }

    // ---------------------------------------------------------------------
    // users / chats / channels
    // ---------------------------------------------------------------------

    public function upsertUser(array $user): void
    {
        $id = $user['user_id'];
        $username = $user['username'] ?? null;
        $phone = $user['phone'] ?? null;

        $this->upsert('users', [
            'user_id' => $id,
            'access_hash' => $user['access_hash'] ?? null,
            'username' => $username,
            'phone' => $phone,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'photo' => $user['photo'] ?? null,
            'bot' => $user['bot'] ?? null,
            'status' => $user['status'] ?? null,
            'raw' => $user['raw'] ?? null,
        ], ['user_id']);

        $this->indexPeer((int) $id, 'user', $username, $phone);
    }

    public function getUser(int $id): ?array
    {
        $rows = $this->driver->query('SELECT * FROM users WHERE user_id = ?', [$id]);

        return isset($rows[0]) ? $rows[0] : null;
    }

    public function upsertChat(array $chat): void
    {
        $id = $chat['id'];
        $username = $chat['username'] ?? null;

        $this->upsert('chats', [
            'id' => $id,
            'access_hash' => $chat['access_hash'] ?? null,
            'title' => $chat['title'] ?? null,
            'username' => $username,
            'participants_count' => $chat['participants_count'] ?? null,
            'photo' => $chat['photo'] ?? null,
            'raw' => $chat['raw'] ?? null,
        ], ['id']);

        $this->indexPeer((int) $id, 'chat', $username, null);
    }

    public function upsertChannel(array $channel): void
    {
        $id = $channel['id'];
        $username = $channel['username'] ?? null;

        $this->upsert('channels', [
            'id' => $id,
            'access_hash' => $channel['access_hash'] ?? null,
            'title' => $channel['title'] ?? null,
            'username' => $username,
            'participants_count' => $channel['participants_count'] ?? null,
            'photo' => $channel['photo'] ?? null,
            'raw' => $channel['raw'] ?? null,
        ], ['id']);

        $this->indexPeer((int) $id, 'channel', $username, null);
    }

    public function getChat(int $id): ?array
    {
        $rows = $this->driver->query('SELECT * FROM chats WHERE id = ?', [$id]);

        return isset($rows[0]) ? $rows[0] : null;
    }

    // ---------------------------------------------------------------------
    // peer resolution (single map)
    // ---------------------------------------------------------------------

    public function resolvePeer(string $usernameOrPhone): ?array
    {
        $rows = $this->driver->query(
            'SELECT * FROM peers WHERE username = ? OR phone = ?',
            [$usernameOrPhone, $usernameOrPhone]
        );

        return isset($rows[0]) ? $rows[0] : null;
    }

    public function indexPeer(int $peerId, string $type, ?string $username, ?string $phone): void
    {
        $this->upsert('peers', [
            'peer_id' => $peerId,
            'type' => $type,
            'username' => $username,
            'phone' => $phone,
        ], ['peer_id']);
    }

    // ---------------------------------------------------------------------
    // messages
    // ---------------------------------------------------------------------

    public function upsertMessage(array $msg): void
    {
        $this->upsert('messages', [
            'peer_id' => $msg['peer_id'],
            'id' => $msg['id'],
            'from_id' => $msg['from_id'] ?? null,
            'date' => $msg['date'] ?? null,
            'message' => $msg['message'] ?? null,
            'media' => $msg['media'] ?? null,
            'entities' => $msg['entities'] ?? null,
            'raw' => $msg['raw'] ?? null,
        ], ['peer_id', 'id']);
    }

    public function getMessage(int $peerId, int $id): ?array
    {
        $rows = $this->driver->query(
            'SELECT * FROM messages WHERE peer_id = ? AND id = ?',
            [$peerId, $id]
        );

        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * CROSS-ACCOUNT: returns every message authored by $fromId across all
     * accounts (the `messages` table has no account scope — peer_id alone
     * distinguishes the chats of different logged-in accounts).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMessagesBySender(int $fromId): array
    {
        return $this->driver->query(
            'SELECT * FROM messages WHERE from_id = ? ORDER BY peer_id, id',
            [$fromId]
        );
    }

    // ---------------------------------------------------------------------
    // files
    // ---------------------------------------------------------------------

    public function upsertFile(int $volumeId, int $localId, string $fileReferenceBytes, string $type): void
    {
        $this->upsert('files', [
            'volume_id' => $volumeId,
            'local_id' => $localId,
            'file_reference' => $fileReferenceBytes,
            'type' => $type,
        ], ['volume_id', 'local_id']);
    }

    public function getFile(int $volumeId, int $localId): ?array
    {
        $rows = $this->driver->query(
            'SELECT * FROM files WHERE volume_id = ? AND local_id = ?',
            [$volumeId, $localId]
        );

        return isset($rows[0]) ? $rows[0] : null;
    }

    // ---------------------------------------------------------------------
    // single source of truth join
    // ---------------------------------------------------------------------

    public function linkAccountEntity(int $accountId, int $entityId, string $relationship): void
    {
        $this->upsert('account_entities', [
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'relationship' => $relationship,
        ], ['account_id', 'entity_id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAccountEntities(int $accountId): array
    {
        return $this->driver->query(
            'SELECT * FROM account_entities WHERE account_id = ? ORDER BY entity_id',
            [$accountId]
        );
    }
}
