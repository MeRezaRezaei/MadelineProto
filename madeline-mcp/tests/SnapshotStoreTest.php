<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\SnapshotStore;
use PHPUnit\Framework\TestCase;

final class SnapshotStoreTest extends TestCase
{
    public function testFreshTakeReturnsFirstSliceAndAdvances(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i], range(1, 5));
        $token = SnapshotStore::create($rows, ['scope' => 'personal']);

        $page = SnapshotStore::take($token, 2);
        $this->assertSame(5, $page['total']);
        $this->assertSame(2, $page['returned']);
        $this->assertSame([['id' => 1], ['id' => 2]], $page['items']);
        $this->assertFalse($page['done']);
        $this->assertSame(['scope' => 'personal'], $page['meta']);
    }

    public function testContinuationKeepsSameOrder(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i], range(1, 5));
        $token = SnapshotStore::create($rows, []);

        SnapshotStore::take($token, 2); // consume 1,2
        $page = SnapshotStore::take($token, 2); // 3,4
        $this->assertSame([['id' => 3], ['id' => 4]], $page['items']);
        $this->assertFalse($page['done']);
    }

    public function testDoneFlagWhenExhausted(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i], range(1, 3));
        $token = SnapshotStore::create($rows, []);

        SnapshotStore::take($token, 2); // 1,2 -> not done
        $page = SnapshotStore::take($token, 2); // 3 -> done
        $this->assertSame([['id' => 3]], $page['items']);
        $this->assertTrue($page['done']);
    }

    public function testExtendAppendsForOlderPages(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i], range(1, 3));
        $token = SnapshotStore::create($rows, ['oldest_id' => 1]);

        SnapshotStore::take($token, 3); // exhaust
        SnapshotStore::extend($token, [['id' => 0], ['id' => -1]], ['oldest_id' => 0]);
        $page = SnapshotStore::take($token, 3); // appended page

        $this->assertSame([['id' => 0], ['id' => -1]], $page['items']);
        $this->assertSame(['oldest_id' => 0], $page['meta']);
    }

    public function testUnknownTokenReturnsNull(): void
    {
        $this->assertNull(SnapshotStore::take('deadbeef', 10));
        $this->assertFalse(SnapshotStore::exists('deadbeef'));
    }
}
