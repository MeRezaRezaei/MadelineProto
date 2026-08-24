<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Assistant\OmniClient;
use MadelineMcp\Assistant\ToolBridge;
use MadelineMcp\Assistant\AssistantBot;
use PHPUnit\Framework\TestCase;

final class AssistantTest extends TestCase
{
    public function testNameRoundtrip(): void
    {
        $this->assertSame('session__get_limits', ToolBridge::encode('session.get_limits'));
        $this->assertSame('bot.invoke', ToolBridge::decode('bot__invoke'));
        $this->assertSame('resolve_peer', ToolBridge::decode(ToolBridge::encode('resolve_peer')));
    }

    public function testChunkText(): void
    {
        $this->assertCount(1, AssistantBot::chunkText('short', 4096));
        $long = \str_repeat("line\n", 2000); // 10000 chars
        $chunks = AssistantBot::chunkText($long, 4096);
        $this->assertGreaterThan(1, \count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(4096, \mb_strlen($c));
        }
        $this->assertSame($long, \implode('', $chunks));
    }

    /** @requires extension curl */
    public function testConfigResolution(): void
    {
        $file = \getenv('HOME') . '/.config/madeline-mcp/omniroute.json';
        if (!\is_file($file)) {
            $this->markTestSkipped('no local omniroute config');
        }
        $cfg = OmniClient::config();
        $this->assertNotEmpty($cfg['base']);
        $this->assertStringEndsWith('/v1', \rtrim($cfg['base'], '/'));
    }
}
