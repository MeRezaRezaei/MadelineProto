<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private RelationalStore $store;
    private FakeTelegramGateway $gw;
    private BackupService $svc;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
        $this->gw = new FakeTelegramGateway();
        $this->store->upsertBackupBucket(['name' => 'mysql-main', 'channel_id' => 7, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => '', 'check_interval' => 900, 'stale_after' => 3900]);
        $this->svc = new BackupService($this->store, $this->gw);
    }

    public function testBackupMarksCompleted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arc');
        file_put_contents($file, str_repeat('X', 2500)); // 2 parts @ 1500
        $jobId = $this->svc->backup('mysql-main', $file);
        $job = $this->store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(2, (int) $job['part_count']);
        $this->assertCount(2, json_decode($job['message_ids'], true));
        unlink($file);
    }

    public function testUnknownBucketFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->backup('nope', tempnam(sys_get_temp_dir(), 'x'));
    }
}
