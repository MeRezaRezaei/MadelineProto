<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupE2ETest extends TestCase
{
    public function testFullPipelineAndStaleAlert(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();

        // 1) provision
        $bucket = (new BackupProvisioner($store, $gw))->provision('mysql-main', 'admin');
        $this->assertSame('mysql-main', $bucket->name);

        // 2) upload an archive
        $file = tempnam(sys_get_temp_dir(), 'e2e');
        file_put_contents($file, str_repeat('Z', 2500));
        $jobId = (new BackupService($store, $gw))->backup('mysql-main', $file);
        $job = $store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertCount((int) $job['part_count'], json_decode($job['message_ids'], true));
        unlink($file);

        // 3) verifier: healthy right after upload (last_checked advances)
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertFalse($gw->alertSent());

        // 4) simulate staleness: no new message + bucket stale_after=-1
        $store->updateBackupJob($jobId, ['last_checked_message_id' => 1]);
        $gw->setLatestMessageId(1); // no new message arrived since last check
        $store->upsertBackupBucket([
            'name' => 'mysql-main',
            'channel_id' => $bucket->channelId,
            'channel_title' => 't',
            'bot_token' => 'x',
            'bot_username' => 'u_bot',
            'alert_peer' => 'admin',
            'check_interval' => 900,
            'stale_after' => -1,
        ]);
        $verifier->tick();
        $this->assertTrue($gw->alertSent());
    }
}
