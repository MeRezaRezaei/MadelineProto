<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Limits\CategoryMapper;
use MadelineMcp\Limits\LimitsRepository;
use MadelineMcp\Limits\UsageTracker;
use PHPUnit\Framework\TestCase;

final class LimitsTest extends TestCase
{
    private string $tmpCache;

    protected function setUp(): void
    {
        $this->tmpCache = \sys_get_temp_dir() . '/madeline-mcp-limits-test-' . \uniqid();
        \mkdir($this->tmpCache, 0777, true);
        \putenv('MADELINE_CACHE_DIR=' . $this->tmpCache);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpCache . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpCache);
        \putenv('MADELINE_CACHE_DIR');
    }

    public function testCategoryMapperMapsAiCriticalTools(): void
    {
        self::assertSame('resolve', CategoryMapper::map('resolve_peer')['category']);
        self::assertSame('search.username_resolve_limit', CategoryMapper::map('accounts.resolveUsername')['limit_id']);
        self::assertSame('message', CategoryMapper::map('send_message')['category']);
        self::assertSame('message', CategoryMapper::map('messages.sendMessage')['category']);
        self::assertSame('creation', CategoryMapper::map('channels.createChannel')['category']);
        self::assertNull(CategoryMapper::map('get_me'));
        self::assertNull(CategoryMapper::map('account.updateProfile'));
    }

    public function testRepositoryServesBundledSnapshotOffline(): void
    {
        // No network writes expected: cache dir is empty, fetch may fail -> bundled fallback.
        $repo = new LimitsRepository('en');
        $snap = $repo->snapshot(true); // force refresh path; offline-safe
        self::assertNotEmpty($snap['categories']);
        $ids = \array_column($snap['categories'], 'id');
        self::assertContains('messages', $ids);
        self::assertContains('search', $ids);

        // Free vs premium numeric parsing on the resolve limit (200/day).
        $lim = $repo->numericLimit('search.username_resolve_limit', false);
        self::assertSame(200, $lim['limit']);
    }

    public function testUsageTrackerCountsAndLocks(): void
    {
        $t = UsageTracker::forSession('limits-test');
        self::assertNull($t->blocked('resolve'));

        $t->record('resolve');
        $t->record('resolve', 2);
        self::assertSame(3, $t->usedToday('resolve'));

        $t->recordFloodWait(120, 'messages.sendMessage', 'message');
        $b = $t->blocked('message');
        self::assertNotNull($b);
        self::assertGreaterThan(100, $b['remaining']);
        self::assertSame('message', $b['scope']); // category lock wins for its own category

        // Unrelated category is NOT blocked by the message-scoped part,
        // but IS blocked by the global lock from the same flood wait.
        self::assertNotNull($t->blocked('resolve'));

        $t->clearCooldowns(null);
        self::assertNull($t->blocked('message'));
        self::assertCount(1, $t->floodWaits());

        // Rate window
        $t->record('message');
        self::assertSame(1, $t->rate('message', 60));
    }
}
