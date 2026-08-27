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

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Schema acceptance tests.
 *
 * The SQLite variant runs with zero external services (in-memory database).
 * The PostgreSQL variant is gated behind the MADLINE_PG env variable.
 */
class SchemaTest extends TestCase
{
    private const EXPECTED_TABLES = [
        'accounts',
        'users',
        'chats',
        'channels',
        'peers',
        'messages',
        'dialogs',
        'files',
        'account_entities',
        'sync_targets',
        'fetch_jobs',
    ];

    private function migrateSqlite(): PdoDriver
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();

        return $driver;
    }

    public function testAllTablesExistSqlite(): void
    {
        $driver = $this->migrateSqlite();
        $rows = $driver->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('_migrations', 'sqlite_sequence')"
        );
        $tables = array_column($rows, 'name');
        sort($tables);

        $expected = self::EXPECTED_TABLES;
        sort($expected);

        $this->assertSame($expected, $tables);
    }

    public function testUserIdIsPrimaryKeyAndNotAutoIncrementSqlite(): void
    {
        $driver = $this->migrateSqlite();
        $explicitId = 123456789012345;

        $driver->exec(
            'INSERT INTO users (user_id, first_name) VALUES (?, ?)',
            [$explicitId, 'Alice']
        );

        $rows = $driver->query('SELECT user_id FROM users WHERE user_id = ?', [$explicitId]);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $explicitId, (string) $rows[0]['user_id']);

        // A second, distinct explicit id is stored unchanged (no auto-increment).
        $otherId = 987654321098765;
        $driver->exec('INSERT INTO users (user_id, first_name) VALUES (?, ?)', [$otherId, 'Bob']);
        $rows = $driver->query('SELECT user_id FROM users ORDER BY user_id');
        $this->assertSame(
            [(string) $explicitId, (string) $otherId],
            array_map('strval', array_column($rows, 'user_id'))
        );
    }

    public function testFileReferenceRoundTripsRawBytesSqlite(): void
    {
        $driver = $this->migrateSqlite();
        $reference = random_bytes(64) . "\x00\x01\x02\xff\xfe";

        $driver->exec(
            'INSERT INTO files (volume_id, local_id, file_reference, type) VALUES (?, ?, ?, ?)',
            [111, 222, $reference, 'photo']
        );

        $rows = $driver->query(
            'SELECT file_reference FROM files WHERE volume_id = ? AND local_id = ?',
            [111, 222]
        );

        $stored = $rows[0]['file_reference'];
        $this->assertIsString($stored);
        $this->assertSame($reference, $stored);
    }

    public function testHeavyIndexesExistSqlite(): void
    {
        $driver = $this->migrateSqlite();

        $userIndexes = $driver->listIndexes('users');
        $this->assertContains('users_username', $userIndexes);
        $this->assertContains('users_phone', $userIndexes);
        $this->assertContains('users_user_id', $userIndexes);

        $messageIndexes = $driver->listIndexes('messages');
        $this->assertContains('messages_peer_id_id', $messageIndexes);
        $this->assertContains('messages_from_id', $messageIndexes);
        $this->assertContains('messages_date', $messageIndexes);

        $peerIndexes = $driver->listIndexes('peers');
        $this->assertContains('peers_username', $peerIndexes);
        $this->assertContains('peers_phone', $peerIndexes);
    }

    public function testAccountCredentialsAreEnforcedSqlite(): void
    {
        $driver = $this->migrateSqlite();

        // No row at all → attaching a session must be rejected.
        $this->expectException(RuntimeException::class);
        $driver->assertAccountExistsWithCredentials(42);
    }

    public function testAccountWithCredentialsPassesSqlite(): void
    {
        $driver = $this->migrateSqlite();

        $driver->exec(
            'INSERT INTO accounts (id, api_id, api_hash) VALUES (?, ?, ?)',
            [42, 12345, 'deadbeefcafe']
        );

        // Should not throw.
        $driver->assertAccountExistsWithCredentials(42);
        $this->assertTrue(true);
    }

    public function testMigrationsAreIdempotentSqlite(): void
    {
        $driver = $this->migrateSqlite();

        // Re-running must not error and must not duplicate tables/rows.
        (new Migrations($driver))->migrate();
        (new Migrations($driver))->migrate();

        $rows = $driver->query('SELECT name FROM _migrations');
        $this->assertSame(['0001_schema.sqlite.sql'], array_column($rows, 'name'));
        $this->assertTrue(true);
    }

    public function testSyncTargetsAndFetchJobsTablesExist(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();

        $targets = $driver->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('sync_targets','fetch_jobs')");
        $names = array_column($targets, 'name');
        $this->assertContains('sync_targets', $names);
        $this->assertContains('fetch_jobs', $names);

        $cols = $driver->query("PRAGMA table_info(sync_targets)");
        $this->assertSame(
            ['peer_id', 'type', 'history_since', 'enabled'],
            array_column($cols, 'name'),
        );
    }

    /**
     * @requires extension pdo_pgsql
     */
    public function testSchemaPostgres(): void
    {
        if (!getenv('MADLINE_PG')) {
            $this->markTestSkipped('MADLINE_PG is not set; skipping PostgreSQL variant.');
        }

        $dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline';
        $driver = new PdoDriver($dsn);
        // Wrap everything in a transaction and roll back so reruns never collide
        // on the persistent database.
        $driver->getPdo()->beginTransaction();
        try {
        (new Migrations($driver))->migrate();

        $rows = $driver->query(
            "SELECT tablename AS name FROM pg_tables WHERE schemaname = 'public'"
            . " AND tablename != '_migrations'"
        );
        $tables = array_column($rows, 'name');
        sort($tables);

        $expected = self::EXPECTED_TABLES;
        sort($expected);
        $this->assertSame($expected, $tables);

        $explicitId = random_int(1_000_000_000_000_000, 9_000_000_000_000_000);
        $driver->exec('INSERT INTO users (user_id, first_name) VALUES (?, ?)', [$explicitId, 'Alice']);
        $rows = $driver->query('SELECT user_id FROM users WHERE user_id = ?', [$explicitId]);
        $this->assertSame((string) $explicitId, (string) $rows[0]['user_id']);

        $reference = random_bytes(64) . "\x00\x01\x02\xff\xfe";
        $volumeId = random_int(1, 1_000_000_000);
        $localId = random_int(1, 1_000_000_000);
        $stmt = $driver->getPdo()->prepare(
            'INSERT INTO files (volume_id, local_id, file_reference, type) VALUES (?, ?, decode(?, \'hex\'), ?)'
        );
        $stmt->execute([$volumeId, $localId, bin2hex($reference), 'photo']);
        $rows = $driver->query(
            'SELECT file_reference FROM files WHERE volume_id = ? AND local_id = ?',
            [$volumeId, $localId]
        );
        $raw = $rows[0]['file_reference'];
        if (is_resource($raw)) {
            $raw = stream_get_contents($raw);
        }
        $stored = $raw;
        $this->assertSame($reference, $stored);

        $userIndexes = $driver->listIndexes('users');
        $this->assertContains('users_username', $userIndexes);
        $this->assertContains('users_phone', $userIndexes);

        // Idempotent.
        (new Migrations($driver))->migrate();
        $this->assertTrue(true);
        } finally {
            $driver->getPdo()->rollBack();
        }
    }
}
