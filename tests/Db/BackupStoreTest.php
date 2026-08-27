<?php declare(strict_types=1);
namespace danog\MadelineProto\Db;

use PHPUnit\Framework\TestCase;

final class BackupStoreTest extends TestCase
{
    private PdoDriver $driver;
    private RelationalStore $store;

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
    }

    public function testBucketCrud(): void
    {
        $this->store->upsertBackupBucket([
            'name' => 'mysql-main',
            'channel_id' => 123,
            'channel_title' => 'madeline-gather-abc',
            'bot_token' => '12345:abc',
            'bot_username' => 'madeline_gather_abc_bot',
            'alert_peer' => '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);
        $b = $this->store->getBackupBucket('mysql-main');
        $this->assertNotNull($b);
        $this->assertSame(123, (int) $b['channel_id']);
        $this->assertCount(1, $this->store->listBackupBuckets());
        $this->store->deleteBackupBucket((int) $b['id']);
        $this->assertNull($this->store->getBackupBucket('mysql-main'));
    }

    public function testJobStateMachine(): void
    {
        $this->store->upsertBackupBucket(['name' => 'b', 'channel_id' => 9, 'channel_title' => 't', 'bot_token' => null, 'bot_username' => null, 'alert_peer' => '', 'check_interval' => 900, 'stale_after' => 3900]);
        $bucket = $this->store->getBackupBucket('b');
        $jobId = $this->store->insertBackupJob([
            'bucket_id' => (int) $bucket['id'],
            'status' => 'pending',
            'archive_name' => 'dump.sql.zip',
            'size' => 0,
            'sha256' => null,
            'part_count' => 0,
            'message_ids' => null,
            'last_checked_message_id' => null,
            'completed_at' => null,
            'error' => null,
        ]);
        $this->store->updateBackupJob($jobId, ['status' => 'completed', 'part_count' => 2, 'message_ids' => json_encode([11, 12]), 'completed_at' => date('c')]);
        $job = $this->store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(2, (int) $job['part_count']);
        $latest = $this->store->getLatestBackupJob((int) $bucket['id']);
        $this->assertSame($jobId, (int) $latest['id']);
    }
}
