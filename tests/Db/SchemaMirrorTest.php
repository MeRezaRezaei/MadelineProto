<?php

namespace danog\MadelineProto\Test\Db;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

class SchemaMirrorTest extends TestCase
{
    private PdoDriver $driver;
    private RelationalStore $store;

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        $migrations = new Migrations($this->driver, __DIR__ . '/../../src/Db/migrations');
        $migrations->migrate();
        $this->store = new RelationalStore($this->driver);
    }

    public function testSchemaTablesExist(): void
    {
        $pdo = $this->driver->getPdo();
        $expectedTables = [
            'accounts', 'users', 'chats', 'channels',
            'peers', 'messages', 'dialogs', 'files',
            'account_entities', 'sync_targets', 'fetch_jobs',
            'backup_buckets', 'backup_jobs'
        ];

        foreach ($expectedTables as $table) {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            $this->assertNotEmpty($stmt->fetchAll(), "Table '$table' is missing in SQLite schema mirror");
        }
    }

    public function testSafeSoftDeletePreservesMessageRecord(): void
    {
        $messageTl = [
            '_' => 'message',
            'id' => 777,
            'peer_id' => -1009876543210,
            'from_id' => 123456,
            'date' => 1700000000,
            'message' => 'Original secret message',
            'media' => null,
            'entities' => []
        ];

        $this->store->upsertMessage($messageTl);

        $saved = $this->store->getMessage(-1009876543210, 777);
        $this->assertNotNull($saved);
        $this->assertEquals('Original secret message', $saved['message']);
        $this->assertNull($saved['deleted_at']);

        // Soft delete
        $this->store->softDeleteMessage(-1009876543210, 777);

        $afterDelete = $this->store->getMessage(-1009876543210, 777);
        $this->assertNotNull($afterDelete, 'Message should still exist in PostgreSQL/SQLite for audit retention');
        $this->assertNotNull($afterDelete['deleted_at'], 'deleted_at must be populated');
        $this->assertEquals('Original secret message', $afterDelete['message']);
    }
}
