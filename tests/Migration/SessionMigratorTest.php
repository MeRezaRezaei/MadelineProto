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

namespace danog\MadelineProto\Test\Migration;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Migration\SessionMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Unit and integration tests for SessionMigrator and bin/madeline-migrate-session.
 */
class SessionMigratorTest extends TestCase
{
    private string $tempDir;
    private string $sqliteDbFile;
    private string $sqliteDsn;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/madeline_migration_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/sessions', 0777, true);

        $this->sqliteDbFile = $this->tempDir . '/test.db';
        $this->sqliteDsn = 'sqlite:' . $this->sqliteDbFile;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Create a mock session directory with safe.php and auxiliary files.
     */
    private function createMockSession(
        string $sessionName,
        int $userId,
        int $apiId,
        string $apiHash,
        string $authState = 'authorized',
        ?array $userData = null
    ): string {
        $sessionDir = $this->tempDir . '/sessions/' . $sessionName;
        mkdir($sessionDir, 0777, true);

        $user = $userData ?? [
            'user_id' => $userId,
            'id' => $userId,
            'first_name' => 'User ' . $userId,
            'last_name' => 'Test',
            'username' => 'user_' . $userId,
            'phone' => '+1' . str_pad((string) $userId, 10, '0', STR_PAD_LEFT),
            'raw' => json_encode(['user_id' => $userId, 'first_name' => 'User ' . $userId, 'username' => 'user_' . $userId]),
        ];

        $sessionData = [
            'api_id' => $apiId,
            'api_hash' => $apiHash,
            'user_id' => $userId,
            'auth_state' => $authState,
            'user' => $user,
            'authorization' => [
                'user' => $user,
                'state' => $authState,
            ],
        ];

        // Format as MadelineProto safe.php binary header + serialized payload
        $header = '<?php __HALT_COMPILER();' . chr(3) . chr(PHP_MAJOR_VERSION) . chr(PHP_MINOR_VERSION) . chr(0);
        $payload = $header . serialize($sessionData);

        file_put_contents($sessionDir . '/safe.php', $payload);
        file_put_contents($sessionDir . '/lightState.php', '<?php // lightState');
        file_put_contents($sessionDir . '/ipcState.php', '<?php // ipcState');
        file_put_contents($sessionDir . '/lock', '');

        return $sessionDir;
    }

    public function testMigrateMockSessionToSqlite(): void
    {
        $sessionName = 'main_account';
        $userId = 501558149;
        $apiId = 123456;
        $apiHash = 'abcdef0123456789abcdef0123456789';

        $sessionDir = $this->createMockSession($sessionName, $userId, $apiId, $apiHash, 'authorized');

        $migrator = new SessionMigrator($this->sqliteDsn);
        $result = $migrator->migrate($sessionDir);

        $this->assertTrue($result['success']);
        $this->assertSame($userId, $result['user_id']);
        $this->assertSame($apiId, $result['api_id']);
        $this->assertSame($apiHash, $result['api_hash']);
        $this->assertSame('authorized', $result['auth_state']);

        // Verify database contents
        $driver = new PdoDriver($this->sqliteDsn);
        $store = new RelationalStore($driver);

        $account = $store->getAccount($userId);
        $this->assertNotNull($account);
        $this->assertSame($userId, (int) $account['id']);
        $this->assertSame($apiId, (int) $account['api_id']);
        $this->assertSame($apiHash, $account['api_hash']);
        $this->assertSame('authorized', $account['auth_state']);
        $this->assertNotEmpty($account['session_blob']);

        $user = $store->getUser($userId);
        $this->assertNotNull($user);
        $this->assertSame($userId, (int) $user['user_id']);
        $this->assertSame('User ' . $userId, $user['first_name']);
        $this->assertSame('user_' . $userId, $user['username']);

        $entities = $store->getAccountEntities($userId);
        $this->assertCount(1, $entities);
        $this->assertSame($userId, (int) $entities[0]['entity_id']);
        $this->assertSame('self', $entities[0]['relationship']);

        // Peers table must be populated for username/phone resolution
        $resolved = $store->resolvePeer('user_' . $userId);
        $this->assertNotNull($resolved);
        $this->assertSame($userId, (int) $resolved['peer_id']);
    }

    public function testMigrateWithCleanupDeletesSessionFiles(): void
    {
        $sessionName = 'cleanup_account';
        $userId = 601234567;
        $apiId = 998877;
        $apiHash = 'hash998877665544';

        $sessionDir = $this->createMockSession($sessionName, $userId, $apiId, $apiHash);
        $this->assertFileExists($sessionDir . '/safe.php');

        $migrator = new SessionMigrator($this->sqliteDsn);
        $result = $migrator->migrate($sessionDir, cleanup: true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['cleaned_up']);

        // Safe.php and directory should no longer exist
        $this->assertFileDoesNotExist($sessionDir . '/safe.php');
        $this->assertDirectoryDoesNotExist($sessionDir);
    }

    public function testMigrateSessionByNameResolvesDefaultLocation(): void
    {
        $sessionName = 'named_account';
        $userId = 701234567;
        $apiId = 445566;
        $apiHash = 'hash445566778899';

        $this->createMockSession($sessionName, $userId, $apiId, $apiHash);

        $migrator = new SessionMigrator($this->sqliteDsn, baseDir: $this->tempDir . '/sessions');
        $result = $migrator->migrate($sessionName);

        $this->assertTrue($result['success']);
        $this->assertSame($userId, $result['user_id']);
    }

    public function testMigrateNonExistentSessionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $migrator = new SessionMigrator($this->sqliteDsn, baseDir: $this->tempDir . '/sessions');
        $migrator->migrate('non_existent_session');
    }

    public function testCliScriptExecution(): void
    {
        $sessionName = 'cli_account';
        $userId = 801234567;
        $apiId = 112233;
        $apiHash = 'hashcli11223344';

        $sessionDir = $this->createMockSession($sessionName, $userId, $apiId, $apiHash);

        $binPath = realpath(__DIR__ . '/../../bin/madeline-migrate-session');
        $this->assertNotFalse($binPath, 'bin/madeline-migrate-session must exist');

        $cmd = sprintf(
            'php %s --session=%s --dsn=%s 2>&1',
            escapeshellarg($binPath),
            escapeshellarg($sessionDir),
            escapeshellarg($this->sqliteDsn)
        );

        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);

        $this->assertSame(0, $exitCode, 'CLI script failed: ' . $outputStr);
        $this->assertStringContainsString((string) $userId, $outputStr);
        $this->assertStringContainsString('successfully migrated', strtolower($outputStr));

        // Verify account in database
        $driver = new PdoDriver($this->sqliteDsn);
        $store = new RelationalStore($driver);
        $account = $store->getAccount($userId);
        $this->assertNotNull($account);
        $this->assertSame($userId, (int) $account['id']);
    }

    public function testCliScriptExecutionWithCleanup(): void
    {
        $sessionName = 'cli_cleanup_account';
        $userId = 801234568;
        $apiId = 112234;
        $apiHash = 'hashcli11223345';

        $sessionDir = $this->createMockSession($sessionName, $userId, $apiId, $apiHash);

        $binPath = realpath(__DIR__ . '/../../bin/madeline-migrate-session');
        $this->assertNotFalse($binPath, 'bin/madeline-migrate-session must exist');

        $cmd = sprintf(
            'php %s --session=%s --dsn=%s --cleanup 2>&1',
            escapeshellarg($binPath),
            escapeshellarg($sessionDir),
            escapeshellarg($this->sqliteDsn)
        );

        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);

        $this->assertSame(0, $exitCode, 'CLI script failed: ' . $outputStr);
        $this->assertStringContainsString('removed successfully', $outputStr);
        $this->assertFileDoesNotExist($sessionDir . '/safe.php');
    }

    public function testMigrateThrowsOnMissingApiCredentials(): void
    {
        $sessionName = 'bad_credentials';
        $sessionDir = $this->tempDir . '/sessions/' . $sessionName;
        mkdir($sessionDir, 0777, true);

        $sessionData = [
            'user_id' => 12345,
            // missing api_id and api_hash
        ];

        file_put_contents($sessionDir . '/safe.php', serialize($sessionData));

        $this->expectException(\RuntimeException::class);
        $migrator = new SessionMigrator($this->sqliteDsn);
        $migrator->migrate($sessionDir);
    }

    /**
     * @requires extension pdo_pgsql
     */
    public function testMigrateToPostgres(): void
    {
        if (!getenv('MADLINE_PG')) {
            $this->markTestSkipped('MADLINE_PG is not set; skipping PostgreSQL variant.');
        }

        $pgDsn = 'pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline';
        $sessionName = 'pg_account';
        $userId = random_int(100_000_000, 999_999_999);
        $apiId = 654321;
        $apiHash = 'pghash1234567890';

        $sessionDir = $this->createMockSession($sessionName, $userId, $apiId, $apiHash);

        $migrator = new SessionMigrator($pgDsn);
        $result = $migrator->migrate($sessionDir);

        $this->assertTrue($result['success']);
        $this->assertSame($userId, $result['user_id']);

        $driver = new PdoDriver($pgDsn);
        $store = new RelationalStore($driver);
        $account = $store->getAccount($userId);
        $this->assertNotNull($account);
        $this->assertSame($userId, (int) $account['id']);
        $this->assertSame($apiId, (int) $account['api_id']);

        // Clean up inserted test record
        $store->deleteAccount($userId);
    }
}
