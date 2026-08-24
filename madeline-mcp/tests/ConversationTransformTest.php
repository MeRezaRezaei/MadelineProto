<?php

declare(strict_types=1);

namespace MadelineMcp\Tests;

use MadelineMcp\ApiClient;
use MadelineMcp\ResponseSanitizer;
use MadelineMcp\ToolCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Characterization tests for the conversation transform layer.
 *
 * These cover the pure logic the code review flagged as untested:
 * ResponseSanitizer::toSimple (about/rows reshape), and ToolCatalog's private
 * helpers (conversationPreview, lastSeenText, lastSeenEpoch, conversationsEnvelope).
 * They drive the real code with no Telegram connection (the helpers are pure).
 */
final class ConversationTransformTest extends TestCase
{
    private function tool(): ToolCatalog
    {
        return new ToolCatalog(new ApiClient('test-session'));
    }

    /** Invoke a private method on ToolCatalog for characterization. */
    private function callPrivate(ToolCatalog $c, string $method, array $args): mixed
    {
        $r = new ReflectionMethod(ToolCatalog::class, $method);
        $r->setAccessible(true);
        return $r->invoke($c, ...$args);
    }

    public function testToSimpleReshapesListConversations(): void
    {
        $raw = [
            'filter' => 'all',
            'sort' => 'telegram_default',
            'media' => 'placeholder',
            'include_bots' => false,
            'total' => 28,
            'returned' => 1,
            'page_done' => false,
            'sort_token' => 'abc',
            'conversations' => [['username' => '@x', 'id' => 1, 'last_seen' => '—', 'last_message' => 'hi', 'last_message_was_me' => 0]],
        ];

        $out = ResponseSanitizer::toSimple('list_conversations', $raw);

        self::assertArrayHasKey('about', $out);
        self::assertArrayHasKey('rows', $out);
        self::assertArrayNotHasKey('conversations', $out['about']);
        self::assertSame('all', $out['about']['filter']);
        self::assertSame('telegram_default', $out['about']['sort']);
        self::assertSame([['username' => '@x', 'id' => 1, 'last_seen' => '—', 'last_message' => 'hi', 'last_message_was_me' => 0]], $out['rows']);
    }

    public function testToSimpleReshapesGetConversation(): void
    {
        $raw = [
            'peer' => ['id' => 1],
            'count' => 5,
            'loaded' => 2,
            'returned' => 1,
            'page_done' => false,
            'sort_token' => 't',
            'messages' => [['id' => 1, 'date' => 0, 'out' => false, 'media_type' => 'text', 'text' => 'hi']],
        ];

        $out = ResponseSanitizer::toSimple('get_conversation', $raw);

        self::assertArrayHasKey('about', $out);
        self::assertArrayHasKey('rows', $out);
        self::assertArrayNotHasKey('messages', $out['about']);
        self::assertSame([['id' => 1, 'date' => 0, 'out' => false, 'media_type' => 'text', 'text' => 'hi']], $out['rows']);
    }

    public function testToSimplePassesThroughErrors(): void
    {
        $err = ['_error' => true, 'message' => 'boom'];
        self::assertSame($err, ResponseSanitizer::toSimple('list_conversations', $err));
    }

    public function testConversationPreviewText(): void
    {
        $c = $this->tool();
        self::assertSame('hello', $this->callPrivate($c, 'conversationPreview', [['id' => 1, 'message' => 'hello'], false, 99]));

        $long = str_repeat('a', 300);
        $res = $this->callPrivate($c, 'conversationPreview', [['id' => 2, 'message' => $long], false, 99]);
        self::assertSame(200, mb_strlen($res));
        self::assertStringStartsWith('aaa', $res);
    }

    public function testConversationPreviewAction(): void
    {
        $c = $this->tool();
        $res = $this->callPrivate($c, 'conversationPreview', [['action' => ['_' => 'messageActionChannelCreate']], false, 99]);
        self::assertSame('[messageActionChannelCreate]', $res);
    }

    public function testConversationPreviewMediaPlaceholder(): void
    {
        $c = $this->tool();
        $res = $this->callPrivate($c, 'conversationPreview', [['id' => 20, 'media' => ['_' => 'messageMediaPhoto']], false, 99]);
        self::assertSame('media:messageMediaPhoto', $res);
    }

    public function testConversationPreviewMediaRef(): void
    {
        $c = $this->tool();
        $res = $this->callPrivate($c, 'conversationPreview', [['id' => 20, 'media' => ['_' => 'messageMediaPhoto']], true, 99]);
        self::assertSame('media:#20', $res);
    }

    public function testConversationPreviewNullMessage(): void
    {
        $c = $this->tool();
        self::assertSame('', $this->callPrivate($c, 'conversationPreview', [null, false, 99]));
    }

    public function testLastSeenTextVariants(): void
    {
        $c = $this->tool();
        self::assertSame('online', $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusOnline']]));
        self::assertSame('recently', $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusRecently']]));
        self::assertSame('last week', $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusLastWeek']]));
        self::assertSame('last month', $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusLastMonth']]));
        self::assertSame('offline', $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusOffline']]));
        self::assertSame(date('Y-m-d H:i', 1700000000), $this->callPrivate($c, 'lastSeenText', [['_' => 'userStatusOffline', 'was_online' => 1700000000]]));
        self::assertSame('—', $this->callPrivate($c, 'lastSeenText', [null]));
    }

    public function testLastSeenEpochOrdering(): void
    {
        $c = $this->tool();
        $online = $this->callPrivate($c, 'lastSeenEpoch', [['_' => 'userStatusOnline']]);
        $recently = $this->callPrivate($c, 'lastSeenEpoch', [['_' => 'userStatusRecently']]);
        $week = $this->callPrivate($c, 'lastSeenEpoch', [['_' => 'userStatusLastWeek']]);
        $month = $this->callPrivate($c, 'lastSeenEpoch', [['_' => 'userStatusLastMonth']]);
        $none = $this->callPrivate($c, 'lastSeenEpoch', [null]);

        self::assertGreaterThan($recently, $online);
        self::assertGreaterThan($week, $recently);
        self::assertGreaterThan($month, $week);
        self::assertGreaterThan($none, $month);
    }

    public function testConversationsEnvelopeIncludesIncludeBots(): void
    {
        $c = $this->tool();
        $page = ['total' => 5, 'returned' => 1, 'items' => [['username' => '@a', 'id' => 1, 'last_seen' => '—', 'last_message' => 'x', 'last_message_was_me' => 0]], 'done' => false, 'meta' => []];

        $out = $this->callPrivate($c, 'conversationsEnvelope', ['all', 'telegram_default', false, true, $page, 'tok']);
        self::assertSame(true, $out['include_bots']);
        self::assertSame('all', $out['filter']);
        self::assertSame('telegram_default', $out['sort']);
        self::assertSame('placeholder', $out['media']);
        self::assertSame('tok', $out['sort_token']);
        self::assertSame($page['items'], $out['conversations']);

        $outFalse = $this->callPrivate($c, 'conversationsEnvelope', ['all', 'telegram_default', false, false, $page, 'tok']);
        self::assertSame(false, $outFalse['include_bots']);
    }
}
