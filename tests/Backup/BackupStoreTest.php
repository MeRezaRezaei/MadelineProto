<?php declare(strict_types=1);

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Backup\BackupStore;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use PHPUnit\Framework\TestCase;

class BackupStoreTest extends TestCase
{
    private BackupStore $store;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new BackupStore($driver);
    }

    public function testSetChannelUpsertAndSalt(): void
    {
        $this->assertNull($this->store->getChannel('vault'));
        $this->store->setChannel('vault', -100123, 'aabb');
        $this->assertSame(-100123, $this->store->getChannel('vault'));
        $this->assertSame('aabb', $this->store->getSalt('vault'));
        $this->store->setChannel('vault', -100999, 'ccdd'); // idempotent upsert
        $this->assertSame(-100999, $this->store->getChannel('vault'));
        $this->assertSame('ccdd', $this->store->getSalt('vault'));
    }

    public function testChunkRoundTripAndFind(): void
    {
        $this->assertNull($this->store->findChunk('deadbeef'));
        $this->store->recordChunk('deadbeef', 'vault', 7, 'fileid', 10);
        $found = $this->store->findChunk('deadbeef');
        $this->assertSame(7, $found['msg_id']);
        $this->assertSame('fileid', $found['file_id']);
    }

    public function testSnapshotRecordingAndListing(): void
    {
        $this->assertNull($this->store->latestSnapshot('vault'));
        $files = [['path' => 'a.bin', 'size' => 3, 'mtime' => 1, 'sha256' => 'h', 'chunks_json' => '["deadbeef"]']];
        $this->store->recordSnapshot('snap1', 'vault', 42, $files, 3);
        $latest = $this->store->latestSnapshot('vault');
        $this->assertSame('snap1', $latest['snapshot_id']);
        $this->assertSame(42, $latest['manifest_msg_id']);
        $this->assertSame([['path' => 'a.bin', 'size' => 3, 'mtime' => 1, 'sha256' => 'h', 'chunks_json' => '["deadbeef"]']],
            array_values($this->store->snapshotFiles('snap1')));
        $this->store->recordSnapshot('snap2', 'vault', 43, $files, 3);
        $this->assertSame('snap2', $this->store->latestSnapshot('vault')['snapshot_id']);
        $this->assertCount(2, $this->store->listSnapshots('vault'));
    }

    public function testRandomChunkHashesBounds(): void
    {
        $this->store->recordChunk('a', 'vault', 1, 'f1', 1);
        $this->store->recordChunk('b', 'vault', 2, 'f2', 1);
        $picked = $this->store->randomChunkHashes(1);
        $this->assertCount(1, $picked);
        $this->assertContains($picked[0], ['a', 'b']);
    }
}
