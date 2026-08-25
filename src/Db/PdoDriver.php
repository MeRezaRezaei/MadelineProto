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
 * @license   https://opensource.org/licenses/ - AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Db;

use PDO;
use RuntimeException;

/**
 * PDO-backed SQL driver supporting PostgreSQL and SQLite.
 *
 * The dialect is auto-detected from the DSN prefix:
 *  - pgsql: → Postgres
 *  - sqlite: → SQLite
 *
 * No new Composer dependencies are introduced; only the bundled PDO extensions
 * (pdo_pgsql / pdo_sqlite) are used.
 */
class PdoDriver implements SqlDriver
{
    private ?PDO $pdo;
    private string $dialect;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $this->dialect = str_starts_with($dsn, 'pgsql:') ? 'pgsql' : 'sqlite';

        $default = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];
        if ($this->dialect === 'pgsql') {
            // Native prepared statements handle bytea binding correctly.
            $default[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        $this->pdo = new PDO($dsn, $username, $password, $options + $default);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getDialect(): string
    {
        return $this->dialect;
    }

    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * List index names defined on a table.
     *
     * @return array<int, string>
     */
    public function listIndexes(string $table): array
    {
        if ($this->dialect === 'sqlite') {
            $rows = $this->query('PRAGMA index_list(' . $this->pdo->quote($table) . ')');

            return array_values(array_column($rows, 'name'));
        }

        $rows = $this->query('SELECT indexname FROM pg_indexes WHERE tablename = ?', [$table]);

        return array_values(array_column($rows, 'indexname'));
    }

    /**
     * Enforce that a session may only attach to an account that already has
     * valid API credentials. Without an api_id / api_hash pair, the account is
     * not entitled to hold a session blob.
     *
     * @param int|string $accountId Telegram user_id of the owner
     */
    public function assertAccountExistsWithCredentials(int|string $accountId): void
    {
        $rows = $this->query('SELECT id, api_id, api_hash FROM accounts WHERE id = ?', [$accountId]);
        if (!isset($rows[0]) || $rows[0]['api_id'] === null || $rows[0]['api_hash'] === null) {
            throw new RuntimeException(
                'Cannot attach session: account ' . $accountId . ' has no api_id/api_hash.'
            );
        }
    }
}
