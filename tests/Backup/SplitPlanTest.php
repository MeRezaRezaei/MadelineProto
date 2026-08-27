<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use PHPUnit\Framework\TestCase;

final class SplitPlanTest extends TestCase
{
    public function testEmpty(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $this->assertSame([], $s->splitPlan(0, 100));
    }

    public function testExactChunk(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $plan = $s->splitPlan(1500, 1000);
        $this->assertSame([[ 'offset' => 0, 'length' => 1000], [ 'offset' => 1000, 'length' => 500]], $plan);
        $this->assertCount(2, $plan);
    }

    public function testOneChunk(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $this->assertSame([[ 'offset' => 0, 'length' => 10]], $s->splitPlan(10, 1000));
    }
}
