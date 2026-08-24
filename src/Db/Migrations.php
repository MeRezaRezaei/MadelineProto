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

use PDO;

/**
 * Dependency-free, idempotent SQL migration runner.
 *
 * Migrations live in a directory as `<NNNN>_name.<dialect>.sql` files
 * (e.g. `0001_schema.pgsql.sql` / `0001_schema.sqlite.sql`). Applied
 * migrations are tracked in a `_migrations` table, so re-running `migrate()`
 * is a no-op once everything is applied.
 */
class Migrations
{
    private SqlDriver $driver;
    private string $dir;

    public function __construct(SqlDriver $driver, ?string $dir = null)
    {
        $this->driver = $driver;
        $this->dir = $dir ?? __DIR__ . '/migrations';
    }

    /**
     * Apply all pending migrations for the active dialect.
     */
    public function migrate(): void
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedNames();
        $dialect = $this->driver->getDialect();

        foreach ($this->collectFiles($dialect) as $file) {
            $name = basename($file);
            if (isset($applied[$name])) {
                continue;
            }
            foreach ($this->splitStatements((string) file_get_contents($file)) as $statement) {
                $this->driver->exec($statement);
            }
            $this->driver->exec(
                'INSERT INTO _migrations (name, applied_at) VALUES (?, ?)',
                [$name, date('c')]
            );
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->driver->exec(
            'CREATE TABLE IF NOT EXISTS _migrations ('
            . 'name TEXT PRIMARY KEY, '
            . 'applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
    }

    /**
     * @return array<string, true>
     */
    private function appliedNames(): array
    {
        $out = [];
        foreach ($this->driver->query('SELECT name FROM _migrations') as $row) {
            $out[$row['name']] = true;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function collectFiles(string $dialect): array
    {
        $files = glob($this->dir . '/*.' . $dialect . '.sql') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Split a SQL file into individual statements on top-level semicolons,
     * honouring single- and double-quoted string literals.
     *
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inString = false;
        $quote = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($inString) {
                $buffer .= $char;
                if ($char === $quote) {
                    $next = $i + 1 < $length ? $sql[$i + 1] : '';
                    if ($next !== $quote) {
                        $inString = false;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString = true;
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
