<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Limits\CategoryMapper;
use MadelineMcp\Limits\LimitsRepository;
use MadelineMcp\ApiClient;
use MadelineMcp\Limits\UsageTracker;
use MadelineMcp\McpServer;
use MadelineMcp\ToolCatalog;
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

    public function testQuotaDigestIsCompactAndOffline(): void
    {
        $client = new ApiClient('main_account');
        $cat = new \MadelineMcp\Limits\LimitsCatalog();

        UsageTracker::forSession('digest-test')->recordFloodWait(60, 'send_message', 'message');

        $d = $cat->quotaDigest($client, 'digest-test');
        self::assertSame(['resolve_daily', 'creation_daily', 'membership_cap'], \array_keys($d['budgets']));
        self::assertSame(200, $d['budgets']['resolve_daily']['limit']);
        self::assertNotSame([], $d['cooldowns']);
        self::assertArrayHasKey('msg_rate_1m', $d);
        self::assertArrayHasKey('spambot', $d);
        UsageTracker::forSession('digest-test')->clearCooldowns(null);
    }

    public function testQuotaInjectedIntoResponsesAndSkippedWhenIrrelevant(): void
    {
        putenv('MADELINE_SESSION_DIR=' . \dirname(__DIR__) . '/sessions');
        $server = new McpServer(new ApiClient('main_account'), new ToolCatalog(new ApiClient('main_account')));

        // Artificial lock -> send_message is guard-blocked AND response carries _quota.
        UsageTracker::forSession('main_account')->recordFloodWait(45, 'send_message', 'message');
        $resp = $server->processLine('{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"send_message","arguments":{"peer":"x","message":"y"}}}');
        self::assertFalse(isset($resp['error']));
        $payload = \json_decode($resp['result']['content'][0]['text'], true);
        self::assertTrue($payload['_error'] ?? false, 'guard should block the call');
        self::assertArrayHasKey('_quota', $payload);
        self::assertNotSame([], $payload['_quota']['cooldowns'], 'cooldown must be visible up front');
        self::assertSame(420, $payload['code']);

        // Unmapped tool with no cooldowns anywhere: no _quota noise.
        UsageTracker::forSession('main_account')->clearCooldowns(null);
        $resp2 = $server->processLine('{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"get_me","arguments":{}}}');
        $payload2 = \json_decode($resp2['result']['content'][0]['text'], true);
        self::assertArrayNotHasKey('_quota', $payload2);

        // Tracked tool (resolve_peer) always carries budgets even when clean.
        $resp3 = $server->processLine('{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"resolve_peer","arguments":{"peer":"telegram"}}}');
        $payload3 = \json_decode($resp3['result']['content'][0]['text'], true);
        if (!isset($payload3['_error'])) {
            self::assertArrayHasKey('_quota', $payload3);
            self::assertArrayHasKey('budgets', $payload3['_quota']);
        }
        UsageTracker::forSession('main_account')->clearCooldowns(null);
    }
}
