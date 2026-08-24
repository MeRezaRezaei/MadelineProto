<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Bots\BotInvoker;
use MadelineMcp\Bots\BotScanner;
use PHPUnit\Framework\TestCase;

final class BridgeDiffTest extends TestCase
{
    private function msg(int $id, bool $out, string $text = '', string $type = 'message', int $editDate = 0): array
    {
        return ['_' => $type, 'id' => $id, 'out' => $out, 'message' => $text, 'edit_date' => $editDate];
    }

    public function testIncomingOnlyDropsOutgoingAndService(): void
    {
        $msgs = [$this->msg(3, true, 'me'), $this->msg(4, false, 'bot'), ['_' => 'messageService', 'id' => 5, 'out' => false]];
        $in = BotInvoker::incomingOnly($msgs);
        $this->assertSame([4], \array_map(static fn($m) => $m['id'], $in));
    }

    public function testDiffNewAndEdit(): void
    {
        $before = [$this->msg(10, false, 'page 1')];
        $after = [$this->msg(10, false, 'page 2', 'message', 1700000100), $this->msg(11, false, 'brand new')];
        $ev = BotInvoker::diffEvents($before, $after);
        $this->assertCount(2, $ev);
        $this->assertSame(['id' => 10, 'type' => 'edit', 'text' => 'page 2'], \array_intersect_key($ev[0], ['id' => 1, 'type' => 1, 'text' => 1]));
        $this->assertSame('new', $ev[1]['type']);
        $this->assertSame(11, $ev[1]['id']);
    }

    public function testDiffIgnoresUnchanged(): void
    {
        $before = [$this->msg(7, false, 'same')];
        $this->assertSame([], BotInvoker::diffEvents($before, $before));
    }

    public function testSnapshotTracksMaxIncomingAndPrints(): void
    {
        $msgs = [$this->msg(20, false, 'a'), $this->msg(21, true, 'me'), $this->msg(22, false, 'b')];
        $s = BotInvoker::snapshot($msgs);
        $this->assertSame(22, $s['max_in_id']);
        $this->assertArrayHasKey(22, $s['prints']);
        $this->assertArrayNotHasKey(21, $s['prints']);
    }

    public function testButtonsOfMessageParsesCallbackButtons(): void
    {
        $m = ['id' => 30, 'out' => false, 'reply_markup' => ['rows' => [['buttons' => [
            ['_' => 'keyboardButtonCallback', 'text' => 'Next', 'data' => 'pg2'],
            ['_' => 'keyboardButtonUrl', 'text' => 'Docs', 'url' => 'https://x'],
        ]]]]];
        $b = BotScanner::buttonsOfMessage($m);
        $this->assertSame('callback', $b['Next']['type']);
        $this->assertSame(\base64_encode('pg2'), $b['Next']['data']);
        $this->assertSame(30, $b['Next']['msg_id']);
        $this->assertSame('url', $b['Docs']['type']);
    }
}
