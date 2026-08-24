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
 * Minimal SQL driver contract for the MadelineProto relational backend.
 *
 * Implementations must be dependency-free (PDO only) and must never rely on
 * auto-incrementing primary keys: every entity id is supplied explicitly.
 */
interface SqlDriver
{
    /**
     * Execute a statement, returning the number of affected rows.
     *
     * @param array<int|string, mixed> $params Bound parameters
     */
    public function exec(string $sql, array $params = []): int;

    /**
     * Run a SELECT (or any query returning rows) and return all rows as assoc arrays.
     *
     * @param array<int|string, mixed> $params Bound parameters
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Return the active SQL dialect: 'pgsql' or 'sqlite'.
     */
    public function getDialect(): string;

    /**
     * Underlying PDO connection (for dialect-specific operations).
     */
    public function getPdo(): \PDO;
}
