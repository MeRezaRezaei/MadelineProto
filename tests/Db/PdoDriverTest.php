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

namespace danog\MadelineProto\Db;

use PHPUnit\Framework\TestCase;

final class PdoDriverTest extends TestCase
{
    private PdoDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
    }

    public function testDialectDetection(): void
    {
        $this->assertSame('sqlite', $this->driver->getDialect());
    }

    public function testGetPdoReturnsConnection(): void
    {
        $this->assertInstanceOf(\PDO::class, $this->driver->getPdo());
    }

    public function testExecReturnsAffectedRowCount(): void
    {
        $this->driver->exec('CREATE TABLE pdo_t (id INTEGER PRIMARY KEY, v TEXT)');
        $this->assertSame(1, $this->driver->exec('INSERT INTO pdo_t (v) VALUES (?)', ['a']));
        $this->assertSame(0, $this->driver->exec('UPDATE pdo_t SET v = ? WHERE id = ?', ['b', 999]));
    }

    public function testQueryReturnsFetchedRows(): void
    {
        $this->driver->exec('CREATE TABLE pdo_q (id INTEGER PRIMARY KEY, v TEXT)');
        $this->driver->exec('INSERT INTO pdo_q (v) VALUES (?)', ['x']);
        $rows = $this->driver->query('SELECT v FROM pdo_q WHERE id = ?', [1]);
        $this->assertSame([['v' => 'x']], $rows);
    }

    public function testListIndexesReportsDefinedIndexes(): void
    {
        $this->driver->exec('CREATE TABLE pdo_i (id INTEGER PRIMARY KEY, v TEXT)');
        $this->driver->exec('CREATE INDEX pdo_i_v ON pdo_i (v)');
        $this->assertContains('pdo_i_v', $this->driver->listIndexes('pdo_i'));
    }

    public function testAssertAccountExistsRequiresCredentials(): void
    {
        $this->driver->exec('INSERT INTO accounts (id, api_id, api_hash) VALUES (2, 123, \'secret\')');

        // Account with credentials must not throw.
        $this->driver->assertAccountExistsWithCredentials(2);

        // Unknown account must throw (covers the missing-row branch).
        $this->expectException(\RuntimeException::class);
        $this->driver->assertAccountExistsWithCredentials(999);
    }

    public function testCloseDropsConnection(): void
    {
        $this->driver->close();
        $this->expectException(\Error::class);
        $this->driver->query('SELECT 1');
    }
}
