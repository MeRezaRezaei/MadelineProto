<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupVerifierTest extends TestCase
{
    public function testAlertsWhenStale(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $gw->createChannel('t', 'a'); // channel id 1, last msg id starts at 1
        $store->upsertBackupBucket(['name' => 'mysql-main', 'channel_id' => 1, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => 'admin', 'check_interval' => 900, 'stale_after' => -1]);
        // channel had message id 1, but we "advance" simulated time so it's stale:
        $gw->setLatestMessageId(1);
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertTrue($gw->alertSent());
        $this->assertStringContainsString('stale', $gw->lastAlert());
    }

    public function testHealthyNoAlert(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $gw->createChannel('t', 'a');
        $store->upsertBackupBucket(['name' => 'ok', 'channel_id' => 1, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => 'admin', 'check_interval' => 900, 'stale_after' => 3900]);
        $gw->setLatestMessageId(1);
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertFalse($gw->alertSent());
    }

    public function testAlertsOnStuckUploading(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $gw->createChannel('t', 'a');
        $store->upsertBackupBucket(['name' => 'mysql-main', 'channel_id' => 1, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => 'admin', 'check_interval' => 900, 'stale_after' => 3900]);
        $bucket = $store->getBackupBucket('mysql-main');
        $jobId = $store->insertBackupJob([
            'bucket_id' => (int) $bucket['id'],
            'status' => 'uploading',
            'archive_name' => 'dump.sql.zip',
            'size' => 0, 'sha256' => null, 'part_count' => 0,
            'message_ids' => null, 'last_checked_message_id' => null,
            'completed_at' => null, 'error' => null,
        ]);
        // Force run_at well past the upload timeout so the job is "stuck".
        $store->updateBackupJob($jobId, ['run_at' => date('c', time() - 3600)]);
        $verifier = new BackupVerifier($store, $gw, 900, 1800);
        $verifier->tick();
        $this->assertTrue($gw->alertSent());
        $this->assertStringContainsString('stuck', $gw->lastAlert());
    }
}
