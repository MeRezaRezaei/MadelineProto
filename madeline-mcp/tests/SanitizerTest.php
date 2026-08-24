<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ResponseSanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    public function testDropsNullsBitmaskAndHashes(): void
    {
        $in = [
            '_' => 'channel', 'id' => -100123, 'title' => 'T',
            'flags' => 8420, 'flags2' => 1036, 'access_hash' => '-6121667038601244424',
            'scam' => false, 'creator' => false, 'megagroup' => false,
            'username' => null, 'left' => true, 'verified' => true,
        ];
        $out = ResponseSanitizer::clean($in);
        self::assertSame('channel', $out['_']);
        self::assertArrayNotHasKey('flags', $out);
        self::assertArrayNotHasKey('flags2', $out);
        self::assertArrayNotHasKey('access_hash', $out);
        self::assertArrayNotHasKey('username', $out); // null dropped
        self::assertSame(true, $out['left']);          // semantic bool kept
    }

    public function testBlobAndTruncationMarkers(): void
    {
        $b64 = \base64_encode(\random_bytes(400)); // ~536 chars, blob-like
        $out = ResponseSanitizer::clean(['d' => $b64]);
        self::assertStringStartsWith('[blob ', (string) $out['d']);

        $long = \str_repeat('سلام ', 900);
        $out2 = ResponseSanitizer::clean(['t' => $long]);
        self::assertStringEndsWith('ch]', (string) $out2['t']);
        self::assertLessThan(2200, \mb_strlen((string) $out2['t']));
    }

    public function testBotInvokeProjectionIsSingleCanonicalButtonList(): void
    {
        $result = [
            'action' => '/mybots', 'kind' => 'command', 'sent' => true,
            'response' => 'Choose a bot:',
            'new_inline_buttons' => ['A' => ['type' => 'callback']],
            'new_buttons_full' => ['A' => ['type' => 'callback', 'data' => 'Ym90cy8x', 'msg_id' => 7]],
            'buttons' => ['A' => ['type' => 'callback', 'data' => 'Ym90cy8x', 'msg_id' => 7]],
            'wait_seconds' => 10,
            'reply_msg_id' => 7,
            '_quota' => ['budgets' => ['resolve_daily' => ['used' => 1]]],
        ];
        $p = ResponseSanitizer::project('bot.invoke', $result);
        self::assertArrayNotHasKey('new_inline_buttons', $p);
        self::assertArrayNotHasKey('new_buttons_full', $p);
        self::assertArrayNotHasKey('wait_seconds', $p);
        self::assertSame([['text' => 'A', 'type' => 'callback']], $p['buttons']); // payload stripped
        self::assertArrayHasKey('_quota', $p); // untouched

        // url buttons keep their url
        $p2 = ResponseSanitizer::project('bot.invoke', ['buttons' => ['Docs' => ['type' => 'url', 'url' => 'https://x']]]);
        self::assertSame([['text' => 'Docs', 'type' => 'url', 'url' => 'https://x']], $p2['buttons']);
    }

    public function testResolvePeerProjection(): void
    {
        $r = ResponseSanitizer::project('resolve_peer', [
            'Chat' => ['_' => 'channel', 'id' => -1001005640892, 'title' => 'Telegram News',
                'username' => 'telegram', 'verified' => true, 'flags' => 8420,
                'photo' => ['stripped_thumb' => ['bytes' => 'xxx']]],
            'bot_api_id' => -1001005640892, 'channel_id' => -1001005640892, 'type' => 'channel',
        ]);
        self::assertSame([
            'type' => 'channel', 'id' => -1001005640892, 'bot_api_id' => -1001005640892,
            'title' => 'Telegram News', 'username' => 'telegram', 'verified' => true,
        ], $r);
    }

    public function testInvokeProjectionTruncatesEvents(): void
    {
        $in = [
            'action' => '/menu', 'kind' => 'command', 'sent' => true,
            'response' => str_repeat('x', 5000),
            'events' => [
                ['id' => 1, 'type' => 'new', 'text' => str_repeat('y', 900), 'buttons' => ['A' => []]],
                ['id' => 1, 'type' => 'edit', 'text' => 'v2', 'buttons' => []],
            ],
            'buttons' => ['A' => ['type' => 'callback', 'data' => 'SECRET']],
            'reply_msg_id' => 1, 'callback_answer' => null,
        ];
        $d = ResponseSanitizer::project('bot.invoke', $in);
        $this->assertLessThanOrEqual(1600, mb_strlen((string) $d['response']));
        $this->assertSame('edit', $d['events'][1]['type']);
        $this->assertLessThanOrEqual(300, mb_strlen((string) $d['events'][0]['text']));
        $this->assertSame(1, $d['events'][0]['n_buttons']);
        $this->assertArrayNotHasKey('data', $d['buttons'][0] ?? []); // payloads stay server-side
    }

    public function testCallMethodProjectsContainers(): void
    {
        $raw = [
            '_' => 'messages.messages',
            'messages' => [
                [
                    '_' => 'message', 'id' => 10, 'out' => true, 'from_id' => 501, 'peer_id' => 9,
                    'date' => 1700000000, 'message' => 'hello', 'edit_date' => 1700000001,
                    'reply_to' => ['reply_to_msg_id' => 9], 'flags' => 4, 'mentioned' => false,
                ],
                [
                    '_' => 'message', 'id' => 9, 'out' => false, 'peer_id' => 9,
                    'date' => 1699999999, 'action' => ['_' => 'messageActionContactSignUp'],
                ],
            ],
            'users' => [['_' => 'user', 'id' => 501, 'first_name' => 'Reza', 'last_name' => 'R', 'bot' => false]],
            'chats' => [['_' => 'channel', 'id' => 9, 'title' => 'News', 'megagroup' => true]],
        ];
        $p = ResponseSanitizer::project('call_method', $raw);

        // messages projected + bounded
        $this->assertCount(2, $p['messages']);
        $this->assertSame('out', $p['messages'][0]['dir']);
        $this->assertSame('in', $p['messages'][1]['dir']);
        $this->assertSame('hello', $p['messages'][0]['text']);
        $this->assertSame('Reza R', $p['messages'][0]['from']['name']);
        $this->assertSame('action:messageActionContactSignUp', $p['messages'][1]['media_type']);
        $this->assertTrue($p['messages'][0]['edited']);
        $this->assertSame(9, $p['messages'][0]['reply_to']);
        // noise dropped, no raw flag booleans leaked
        $this->assertArrayNotHasKey('flags', $p['messages'][0]);
        $this->assertArrayNotHasKey('mentioned', $p['messages'][0]);

        // users/chats compacted
        $this->assertSame('Reza R', $p['users'][0]['name']);
        $this->assertSame('supergroup', $p['chats'][0]['type']);
    }

    public function testReadProjectionStripsDataKeepsShape(): void
    {
        $in = [
            'peer' => '@bf',
            'messages' => [['id' => 5, 'out' => false, 'text' => str_repeat('z', 900), 'inline_buttons' => ['Go' => []]]],
            'inline_buttons' => ['Go' => ['type' => 'callback', 'data' => 'SECRET', 'msg_id' => 5]],
        ];
        $d = ResponseSanitizer::project('bot.read', $in);
        $this->assertLessThanOrEqual(301, mb_strlen((string) $d['messages'][0]['text']));
        $this->assertSame(1, $d['messages'][0]['n_buttons']);
        // inline_buttons becomes a list of {text,type,msg_id[,url]} — data stripped.
        $go = null;
        foreach ((array) ($d['inline_buttons'] ?? []) as $b) {
            if (($b['text'] ?? '') === 'Go') {
                $go = $b;
            }
        }
        $this->assertNotNull($go);
        $this->assertArrayNotHasKey('data', $go); // payloads stay server-side
        $this->assertSame('callback', $go['type']);
    }
}
