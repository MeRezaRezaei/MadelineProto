<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\Bots\BotInvoker;
use MadelineMcp\Bots\BotScanner;
use PHPUnit\Framework\TestCase;

final class BotScannerTest extends TestCase
{
    private function history(): array
    {
        return ['messages' => [
            // Outgoing command we sent
            ['_'=>'message','id'=>11,'out'=>true,'date'=>100,'message'=>'/start',
             'entities'=>[['_'=>'messageEntityBotCommand','offset'=>0,'length'=>6]]],
            // Bot welcome with reply keyboard
            ['_'=>'message','id'=>12,'out'=>false,'date'=>101,'message'=>'Welcome! Use /help or buttons.',
             'entities'=>[['_'=>'messageEntityBotCommand','offset'=>13,'length'=>5]],
             'reply_markup'=>['_'=>'replyKeyboardMarkup','rows'=>[
                 ['_'=>'keyboardButtonsRow','buttons'=>[['_'=>'keyboardButton','text'=>'Status'],['_'=>'keyboardButton','text'=>'Settings']]],
                 ['_'=>'keyboardButtonsRow','buttons'=>[['_'=>'keyboardButton','text'=>'Help']]],
             ]]],
            // Bot message with inline buttons
            ['_'=>'message','id'=>13,'out'=>false,'date'=>102,'message'=>'Pick one:',
             'reply_markup'=>['_'=>'replyInlineMarkup','rows'=>[
                 ['_'=>'keyboardButtonsRow','buttons'=>[
                     ['_'=>'keyboardButtonCallback','text'=>'Vote A','data'=>"raw-cb-\x01"],
                     ['_'=>'keyboardButtonUrl','text'=>'Docs','url'=>'https://example.com'],
                 ]],
             ]]],
        ]];
    }

    public function testParsesCommandsKeyboardsAndCallbacks(): void
    {
        $map = BotScanner::fromHistory($this->history(), 'testbot', 'Test Bot', 'about');

        self::assertContains('/start', $map['commands']);
        self::assertContains('/help', $map['commands']); // from entity on incoming text
        $flat = \array_merge(...$map['reply_keyboard']);
        self::assertSame(['Status', 'Settings', 'Help'], $flat);
        self::assertSame('callback', $map['inline_buttons']['Vote A']['type']);
        self::assertSame(13, $map['inline_buttons']['Vote A']['msg_id']);
        self::assertSame('url', $map['inline_buttons']['Docs']['type']);
        self::assertSame('https://example.com', $map['inline_buttons']['Docs']['url']);
        self::assertNotSame('', $map['last_incoming']);
    }

    public function testClassifyAction(): void
    {
        $map = BotScanner::fromHistory($this->history(), 'testbot');
        self::assertSame('command', BotScanner::classifyAction('/settings lang=en', $map));
        self::assertSame('callback', BotScanner::classifyAction('Vote A', $map));
        self::assertSame('reply', BotScanner::classifyAction('Status', $map));
        self::assertSame('reply', BotScanner::classifyAction('Docs', $map)); // url button -> sendable text fallback
        self::assertSame('reply', BotScanner::classifyAction('unknown text', $map));
    }

    public function testCallbackDataRoundTrip(): void
    {
        $map = BotScanner::fromHistory($this->history(), 'testbot');
        self::assertSame("raw-cb-\x01", BotScanner::callbackDataFor('Vote A', $map));
        self::assertNull(BotScanner::callbackDataFor('Docs', $map));
        self::assertSame(13, BotScanner::msgIdFor('Vote A', $map));
    }

    public function testCollectUntilQuietStopsOnQuietWindowAndMerges(): void
    {
        // Simulated clock: deltas appear on first fetch only; quiet window then closes.
        $polls = [
            [['_'=>'message','id'=>5,'out'=>false,'message'=>'hello']],
            [['_'=>'message','id'=>5,'out'=>false,'message'=>'hello']],
            [['_'=>'message','id'=>5,'out'=>false,'message'=>'hello']],
        ];
        $res = BotInvoker::collectUntilQuiet(
            static function () use (&$polls) { return \array_shift($polls) ?? []; },
            [],     // baseline messages (empty => id 5 is NEW)
            15.0,   // max wait seconds
            2.0,    // quiet seconds
            0       // poll interval 0 for tests (no sleeping)
        );
        self::assertCount(1, $res['events']);
        self::assertSame('new', $res['events'][0]['type']);
        self::assertSame('hello', $res['response']);
    }

    public function testCollectUntilQuietMergesMultipleMessagesAndEdits(): void
    {
        $polls = [
            [['_'=>'message','id'=>5,'out'=>false,'message'=>'part one'],
             ['_'=>'message','id'=>6,'out'=>false,'message'=>'page 1']],
            [['_'=>'message','id'=>6,'out'=>false,'message'=>'page 1']], // no change
            [['_'=>'message','id'=>6,'out'=>false,'message'=>'page 2']], // edit
            [['_'=>'message','id'=>6,'out'=>false,'message'=>'page 2']],
        ];
        $base = [['_'=>'message','id'=>4,'out'=>false,'message'=>'old menu']];
        $res = BotInvoker::collectUntilQuiet(
            static function () use (&$polls) { return \array_shift($polls) ?? []; },
            $base, 15.0, 2.0, 0
        );
        self::assertCount(3, $res['events']); // new(5), new(6), edit(6)
        self::assertSame("part one\n---\npage 1\n---\npage 2", $res['response']);
        self::assertSame('edit', $res['events'][2]['type']);
    }
}
