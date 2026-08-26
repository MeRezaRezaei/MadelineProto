<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Settings\Database\Memory;
use danog\MadelineProto\Settings\Database\Postgres;
use MadelineMcp\ApiClient;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Validates ApiClient behavior when configured with database settings (PostgreSQL / SQLite / RelationalStore).
 */
final class ApiClientDatabaseTest extends TestCase
{
    private string $tempDir;
    private string $sqliteDbFile;
    private string $sqliteDsn;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/madeline_mcp_db_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0777, true);
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

    public function testInitializesRelationalStoreFromDsn(): void
    {
        $client = new ApiClient('main_account', dsn: $this->sqliteDsn);

        self::assertTrue($client->isRelational());
        self::assertInstanceOf(RelationalStore::class, $client->getRelationalStore());
        self::assertInstanceOf(PdoDriver::class, $client->getDriver());
    }

    public function testInitializesRelationalStoreFromEnv(): void
    {
        putenv('MADELINE_DSN=' . $this->sqliteDsn);
        try {
            $client = new ApiClient('main_account');
            self::assertTrue($client->isRelational());
            self::assertInstanceOf(RelationalStore::class, $client->getRelationalStore());
        } finally {
            putenv('MADELINE_DSN');
        }
    }

    public function testListAccountsQueriesRelationalStore(): void
    {
        $driver = new PdoDriver($this->sqliteDsn);
        $store = new RelationalStore($driver);

        // Run migrations and insert test account & user
        $client = new ApiClient('main_account', store: $store, driver: $driver);

        $userId = 501558149;
        $apiId = 123456;
        $apiHash = 'test_api_hash_123';
        $store->upsertAccount($userId, $apiId, $apiHash, 'authorized', 'mock_blob_data');
        $store->upsertUser([
            'user_id' => $userId,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'raw' => json_encode(['_'=>'user', 'id' => $userId, 'username' => 'testuser', 'first_name' => 'Test', 'last_name' => 'User']),
        ]);

        $accounts = $client->listAccounts();
        self::assertCount(1, $accounts);
        self::assertSame('main_account', $accounts[0]['session_name']);
        self::assertSame($apiId, $accounts[0]['api_id']);
        self::assertSame('LOGGED_IN', $accounts[0]['state']);
        self::assertTrue($accounts[0]['logged_in']);
        self::assertSame('testuser', $accounts[0]['username']);
        self::assertSame($userId, $accounts[0]['id']);
    }

    public function testGetMeAndLoginStateFromStore(): void
    {
        $client = new ApiClient('main_account', dsn: $this->sqliteDsn);
        $store = $client->getRelationalStore();
        self::assertNotNull($store);

        $userId = 501558149;
        $apiId = 123456;
        $apiHash = 'test_api_hash_123';
        $store->upsertAccount($userId, $apiId, $apiHash, 'authorized', 'mock_blob_data');
        $store->upsertUser([
            'user_id' => $userId,
            'username' => 'testuser',
            'first_name' => 'Test',
            'last_name' => 'User',
            'raw' => json_encode(['_'=>'user', 'id' => $userId, 'username' => 'testuser', 'first_name' => 'Test', 'last_name' => 'User']),
        ]);

        $catalog = new ToolCatalog($client);

        $loginState = $catalog->call('get_login_state', ['session_name' => 'main_account']);
        self::assertSame('LOGGED_IN', $loginState['state']);
        self::assertTrue($loginState['logged_in']);

        $me = $catalog->call('get_me', ['session_name' => 'main_account']);
        self::assertSame($userId, $me['id']);
        self::assertSame('testuser', $me['username']);
        self::assertSame('Test', $me['first_name']);
    }

    public function testAddAccountConfigSavesToRelationalStoreWithoutDiskFiles(): void
    {
        $client = new ApiClient('main_account', dsn: $this->sqliteDsn);
        $store = $client->getRelationalStore();
        self::assertNotNull($store);

        $client->addAccountConfig('new_account', 998877, 'hash998877');

        $accounts = $store->listAccounts();
        self::assertNotEmpty($accounts);

        $found = null;
        foreach ($accounts as $acc) {
            if ((int)$acc['api_id'] === 998877) {
                $found = $acc;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('hash998877', $found['api_hash']);
    }

    public function testCreateDatabaseSettingsForPostgres(): void
    {
        $driver = new PdoDriver($this->sqliteDsn);
        $store = new RelationalStore($driver);
        $client = new ApiClient(
            'main_account',
            dsn: 'pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=secret',
            store: $store,
            driver: $driver,
        );
        $dbSettings = $client->createDatabaseSettings('main_account');
        self::assertInstanceOf(Postgres::class, $dbSettings);
        self::assertSame('tcp://127.0.0.1:5432', $dbSettings->getUri());
        self::assertSame('madeline', $dbSettings->getUsername());
        self::assertSame('secret', $dbSettings->getPassword());
        self::assertSame('madeline', $dbSettings->getDatabase());
        self::assertSame('main_account', $dbSettings->getEphemeralFilesystemPrefix());
    }

    public function testCreateDatabaseSettingsForSqliteMemory(): void
    {
        $client = new ApiClient('main_account', dsn: $this->sqliteDsn);
        $dbSettings = $client->createDatabaseSettings('main_account');
        self::assertInstanceOf(Memory::class, $dbSettings);
    }
}
