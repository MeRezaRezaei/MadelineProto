<?php declare(strict_types=1);

namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class CliParseTest extends TestCase
{
    private RelationalStore $store;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
    }

    public function testBucketRoundTripsAndSplitPlanWorks(): void
    {
        $this->store->upsertBackupBucket([
            'name' => 'mysql-main',
            'channel_id' => 5,
            'channel_title' => 't',
            'bot_token' => 'x',
            'bot_username' => 'u_bot',
            'alert_peer' => '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);

        $row = $this->store->getBackupBucket('mysql-main');
        $this->assertNotNull($row);
        $this->assertSame('mysql-main', $row['name']);
        $this->assertSame(5, (int) $row['channel_id']);

        // A second upsert must update rather than duplicate.
        $this->store->upsertBackupBucket([
            'name' => 'mysql-main',
            'channel_id' => 7,
            'channel_title' => 't2',
            'bot_token' => 'y',
            'bot_username' => 'u_bot2',
            'alert_peer' => '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);
        $this->assertSame(1, count($this->store->listBackupBuckets()));
        $this->assertSame(7, (int) $this->store->getBackupBucket('mysql-main')['channel_id']);

        // BackupService splitPlan smoke (no live account needed).
        $gw = new FakeTelegramGateway();
        $plan = (new BackupService($this->store, $gw))->splitPlan(3000000000);
        $this->assertCount(2, $plan);
        $this->assertSame(1500000000, $plan[0]['length']);
    }
}
