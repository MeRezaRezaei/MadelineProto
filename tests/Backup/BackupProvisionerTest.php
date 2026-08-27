<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupProvisionerTest extends TestCase
{
    public function testProvisionsBucket(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $prov = new BackupProvisioner($store, $gw);
        $bucket = $prov->provision('mysql-main', 'me');
        $this->assertSame('mysql-main', $bucket->name);
        $this->assertGreaterThan(0, $bucket->channelId);
        $row = $store->getBackupBucket('mysql-main');
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['bot_token']);
        $this->assertStringEndsWith('_bot', (string) $row['bot_username']);
    }
}
