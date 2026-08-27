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

namespace danog\MadelineProto\Test\E2E;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Migration\SessionMigrator;
use MadelineMcp\ApiClient;
use MadelineMcp\McpServer;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

if (file_exists(__DIR__ . '/../../madeline-mcp/vendor/autoload.php')) {
    require_once __DIR__ . '/../../madeline-mcp/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    $prefix = 'MadelineMcp\\';
    if (\str_starts_with($class, $prefix)) {
        $rel = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
        $file = __DIR__ . '/../../madeline-mcp/src/' . $rel . '.php';
        if (\is_file($file)) {
            require_once $file;
        }
    }
});

/**
 * End-to-End Test for Migrated Sessions with Relational Database and Madeline-MCP.
 *
 * Verifies that a migrated live session (sessions/main_account) operates purely
 * against relational database storage without reading/writing flat disk session
 * files (.safe.php, .lock) and answers MCP tool calls correctly.
 */
class MigratedSessionE2ETest extends TestCase
{
    private string $tempDir;
    private string $emptySessionsDir;
    private string $sqliteDbFile;
    private string $sqliteDsn;
    private string $liveSessionPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/madeline_e2e_migrated_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);

        $this->emptySessionsDir = $this->tempDir . '/empty_sessions';
        mkdir($this->emptySessionsDir, 0777, true);

        $this->sqliteDbFile = $this->tempDir . '/test.db';
        $this->sqliteDsn = 'sqlite:' . $this->sqliteDbFile;

        $repoRoot = dirname(__DIR__, 2);
        $this->liveSessionPath = $repoRoot . '/sessions/main_account';

        // Override session dir to an empty directory to guarantee zero fallback to repo sessions/
        putenv('MADELINE_SESSION_DIR=' . $this->emptySessionsDir);
    }

    protected function tearDown(): void
    {
        putenv('MADELINE_SESSION_DIR');
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
     * Test full migration flow and MCP tool execution over SQLite database.
     */
    public function testMigratedSessionE2EOverSqlite(): void
    {
        // 1. Initialize Relational Database Schema via Migrations
        $driver = new PdoDriver($this->sqliteDsn);
        $migrations = new Migrations($driver);
        $migrations->migrate();

        $store = new RelationalStore($driver);

        // 2. Run SessionMigrator on sessions/main_account
        if (!file_exists($this->liveSessionPath . '/safe.php')) {
            $this->markTestSkipped('sessions/main_account/safe.php not found');
        }

        $migrator = new SessionMigrator($this->sqliteDsn, $store);
        $result = $migrator->migrate($this->liveSessionPath);

        $this->assertTrue($result['success']);
        $this->assertSame(501558149, $result['user_id']);
        $this->assertSame('authorized', $result['auth_state']);

        // Seed sample contact conversation & dialog in RelationalStore
        $contactId = 987654321;
        $store->upsertUser([
            'user_id' => $contactId,
            'username' => 'telegram_assistant',
            'first_name' => 'Telegram',
            'last_name' => 'Assistant',
            'phone' => '+18005550199',
            'raw' => json_encode([
                '_' => 'user',
                'id' => $contactId,
                'username' => 'telegram_assistant',
                'first_name' => 'Telegram',
                'last_name' => 'Assistant',
            ]),
        ]);
        $store->upsertMessage([
            'peer_id' => $contactId,
            'id' => 101,
            'from_id' => $contactId,
            'date' => 1700000000,
            'message' => 'Welcome to MadelineProto with PostgreSQL / SQLite backend!',
            'raw' => json_encode([
                'id' => 101,
                'message' => 'Welcome to MadelineProto with PostgreSQL / SQLite backend!',
            ]),
        ]);
        $store->upsertDialog(501558149, $contactId, 101, 0, 1);

        // 3. Initialize madeline-mcp ApiClient pointing to the database
        $client = new ApiClient('501558149', dsn: $this->sqliteDsn, store: $store, driver: $driver);
        $this->assertTrue($client->isRelational());
        $this->assertSame($store, $client->getRelationalStore());

        $catalog = new ToolCatalog($client);
        $server = new McpServer($client, $catalog);

        // 4. Test list_accounts via McpServer JSON-RPC
        $listAccountsRpc = $server->processLine(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_accounts',
                'arguments' => [],
            ],
        ]));

        $this->assertNotNull($listAccountsRpc);
        $this->assertSame(1, $listAccountsRpc['id']);
        $this->assertArrayNotHasKey('error', $listAccountsRpc);
        $accountsPayload = json_decode($listAccountsRpc['result']['content'][0]['text'], true);
        $this->assertIsArray($accountsPayload);
        $this->assertCount(1, $accountsPayload);
        $this->assertSame(501558149, $accountsPayload[0]['id']);
        $this->assertSame('LOGGED_IN', $accountsPayload[0]['state']);
        $this->assertTrue($accountsPayload[0]['logged_in']);
        $this->assertSame('merezarezaei', $accountsPayload[0]['username']);

        // 5. Test get_login_state via McpServer JSON-RPC
        $loginStateRpc = $server->processLine(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_login_state',
                'arguments' => ['session_name' => '501558149'],
            ],
        ]));

        $this->assertNotNull($loginStateRpc);
        $this->assertSame(2, $loginStateRpc['id']);
        $this->assertArrayNotHasKey('error', $loginStateRpc);
        $loginStatePayload = json_decode($loginStateRpc['result']['content'][0]['text'], true);
        $this->assertSame('LOGGED_IN', $loginStatePayload['state']);
        $this->assertTrue($loginStatePayload['logged_in']);

        // 6. Test get_me via McpServer JSON-RPC
        $getMeRpc = $server->processLine(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_me',
                'arguments' => ['session_name' => '501558149'],
            ],
        ]));

        $this->assertNotNull($getMeRpc);
        $this->assertSame(3, $getMeRpc['id']);
        $this->assertArrayNotHasKey('error', $getMeRpc);
        $mePayload = json_decode($getMeRpc['result']['content'][0]['text'], true);
        $this->assertSame(501558149, $mePayload['id']);
        $this->assertSame('merezarezaei', $mePayload['username']);
        $this->assertSame('Reza', $mePayload['first_name']);

        // 7. Test list_conversations via McpServer JSON-RPC
        $conversationsRpc = $server->processLine(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_conversations',
                'arguments' => ['session_name' => '501558149'],
            ],
        ]));

        $this->assertNotNull($conversationsRpc);
        $this->assertSame(4, $conversationsRpc['id']);
        $this->assertArrayNotHasKey('error', $conversationsRpc);
        $conversationsPayload = json_decode($conversationsRpc['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('rows', $conversationsPayload);
        $this->assertCount(1, $conversationsPayload['rows']);
        $this->assertSame('@telegram_assistant', $conversationsPayload['rows'][0]['username']);
        $this->assertSame($contactId, $conversationsPayload['rows'][0]['id']);
        $this->assertSame('Welcome to MadelineProto with PostgreSQL / SQLite backend!', $conversationsPayload['rows'][0]['last_message']);

        // 8. Assert zero session files or lock files created in emptySessionsDir
        $sessionFiles = scandir($this->emptySessionsDir) ?: [];
        $actualFiles = array_diff($sessionFiles, ['.', '..']);
        $this->assertEmpty($actualFiles, 'Empty sessions directory must remain untouched by database session operations');
    }

    /**
     * @requires extension pdo_pgsql
     */
    public function testMigratedSessionE2EOverPostgres(): void
    {
        if (!getenv('MADLINE_PG')) {
            $this->markTestSkipped('MADLINE_PG is not set; skipping PostgreSQL variant.');
        }

        $pgDsn = 'pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline';
        $driver = new PdoDriver($pgDsn);
        $migrations = new Migrations($driver);
        $migrations->migrate();

        $store = new RelationalStore($driver);

        $testUserId = random_int(100_000_000, 999_999_999);
        $testSessionDir = $this->tempDir . '/pg_mock_session';
        mkdir($testSessionDir, 0777, true);
        file_put_contents($testSessionDir . '/safe.php', '<?php __HALT_COMPILER();' . chr(2) . chr(0) . serialize([
            'user_id' => $testUserId,
            'api_id' => 1821270,
            'api_hash' => 'test_api_hash',
            'auth_state' => 'authorized',
            'user' => [
                'id' => $testUserId,
                'username' => 'test_pg_user',
                'first_name' => 'Test',
                'last_name' => 'User',
            ],
            'session_blob' => 'test_blob_content',
        ]));

        $migrator = new SessionMigrator($pgDsn, $store);
        try {
            $result = $migrator->migrate($testSessionDir);

            $this->assertTrue($result['success']);
            $this->assertSame($testUserId, $result['user_id']);

            $client = new ApiClient((string) $testUserId, dsn: $pgDsn, store: $store, driver: $driver);
            $catalog = new ToolCatalog($client);
            $server = new McpServer($client, $catalog);

            $resp = $server->processLine(json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'get_me', 'arguments' => ['session_name' => (string) $testUserId]],
            ]));

            $this->assertNotNull($resp);
            $me = json_decode($resp['result']['content'][0]['text'], true);
            $this->assertSame($testUserId, $me['id']);
            $this->assertSame('test_pg_user', $me['username']);
        } finally {
            $store->deleteAccount($testUserId);
        }
    }
}
