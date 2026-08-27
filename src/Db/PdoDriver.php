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
    /**
     * Normalize a URL-style DSN (postgres://user:pass@host:5432/db) into the
     * PDO-compatible pgsql: DSN used by MadelineProto Postgres settings.
     *
     * Non-postgres DSNs (e.g. sqlite::memory:) are returned unchanged.
     */
    public static function normalizeDsn(string $dsn): string
    {
        if (str_starts_with($dsn, 'postgres://') || str_starts_with($dsn, 'postgresql://')) {
            $parts = parse_url($dsn);
            $host = $parts['host'] ?? '127.0.0.1';
            $port = isset($parts['port']) ? (int) $parts['port'] : 5432;
            $db = isset($parts['path']) ? ltrim($parts['path'], '/') : 'madeline';
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
            return "pgsql:host={$host};port={$port};dbname={$db};user={$user};password={$pass}";
        }
        return $dsn;
    }

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
        $this->bindParameters($stmt, $params);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bindParameters($stmt, $params);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($this->dialect === 'pgsql') {
            foreach ($rows as &$row) {
                foreach ($row as &$val) {
                    if (is_resource($val)) {
                        $val = stream_get_contents($val);
                    }
                }
            }
        }

        return $rows;
    }

    private function bindParameters(\PDOStatement $stmt, array $params): void
    {
        $isPositional = array_is_list($params);
        foreach ($params as $key => $val) {
            $paramKey = $isPositional ? ($key + 1) : (is_int($key) ? $key + 1 : $key);
            if (is_int($val)) {
                $stmt->bindValue($paramKey, $val, PDO::PARAM_INT);
            } elseif (is_bool($val)) {
                $stmt->bindValue($paramKey, $val, PDO::PARAM_BOOL);
            } elseif ($val === null) {
                $stmt->bindValue($paramKey, null, PDO::PARAM_NULL);
            } elseif (is_resource($val)) {
                $stmt->bindValue($paramKey, $val, PDO::PARAM_LOB);
            } elseif (is_string($val) && str_contains($val, "\0")) {
                $stmt->bindValue($paramKey, $val, PDO::PARAM_LOB);
            } else {
                $stmt->bindValue($paramKey, $val, PDO::PARAM_STR);
            }
        }
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
