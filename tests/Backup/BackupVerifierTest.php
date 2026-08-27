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
}
