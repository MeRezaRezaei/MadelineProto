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

    public function testPickReplyDetectsEditsNotOnlyNewMessages(): void
    {
        $baseline = ['id' => 50, 'edit_date' => 100, 'text' => 'Choose a bot:'];
        $msgs = [
            ['_' => 'message', 'id' => 50, 'out' => false, 'date' => 90, 'edit_date' => 100, 'message' => 'Choose a bot:'],
        ];
        // Nothing changed yet.
        self::assertNull(BotInvoker::pickReply($msgs, $baseline));

        // Pagination edits the SAME message -> must be detected.
        $edited = [['_' => 'message', 'id' => 50, 'out' => false, 'date' => 90, 'edit_date' => 200, 'message' => 'Choose a bot from the list below (page 2)']];
        self::assertSame(200, BotInvoker::pickReply($edited, $baseline)['edit_date']);

        // Same id, no edit_date but text changed -> also detected.
        $silentEdit = [['_' => 'message', 'id' => 50, 'out' => false, 'date' => 90, 'edit_date' => 100, 'message' => 'page 2 content']];
        self::assertNotNull(BotInvoker::pickReply($silentEdit, $baseline));

        // Brand-new message wins.
        $fresh = [['_'=>'message','id'=>51,'out'=>false,'date'=>95,'message'=>'hi']];
        self::assertSame(51, BotInvoker::pickReply($fresh, $baseline)['id']);

        // Outgoing-only history: nothing.
        self::assertNull(BotInvoker::pickReply([['_'=>'message','id'=>52,'out'=>true,'date'=>96,'message'=>'/mybots']], $baseline));
    }
}
