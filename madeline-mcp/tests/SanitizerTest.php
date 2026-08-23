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
}
