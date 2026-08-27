<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\Models\TelegramMessage;
use Danog\LaravelTelegram\Models\TelegramUser;
use Danog\LaravelTelegram\Services\TelegramIngestService;

class TelegramIngestServiceTest extends TestCase
{
    private TelegramIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TelegramIngestService();
    }

    public function testIngestUpdateNewMessage(): void
    {
        $update = [
            '_' => 'updateNewMessage',
            'message' => [
                '_' => 'message',
                'id' => 999,
                'peer_id' => ['user_id' => 123456],
                'from_id' => ['user_id' => 789012],
                'date' => 1700000000,
                'message' => 'Hello Laravel Telegram Mirror',
                'out' => false,
                'media' => [
                    '_' => 'messageMediaDocument',
                    'document' => [
                        'id' => 4567890123,
                        'size' => 1024,
                    ]
                ]
            ]
        ];

        $this->service->ingestUpdate(123456, $update);

        $saved = TelegramMessage::where('peer_id', 123456)->where('id', 999)->first();
        $this->assertNotNull($saved);
        $this->assertEquals('Hello Laravel Telegram Mirror', $saved->message);
        $this->assertEquals(789012, $saved->from_id);
        $this->assertEquals('messageMediaDocument', $saved->media_type);
        $this->assertEquals(hash('sha256', '4567890123'), $saved->media_hash);
    }

    public function testIngestUpdateDeleteMessagesPreservesRecordsViaSoftDelete(): void
    {
        $this->testIngestUpdateNewMessage();

        $deleteUpdate = [
            '_' => 'updateDeleteMessages',
            'messages' => [999],
            'peer_id' => 123456,
        ];

        $this->service->ingestUpdate(123456, $deleteUpdate);

        // Normal query returns null (soft-deleted)
        $this->assertNull(TelegramMessage::where('peer_id', 123456)->where('id', 999)->first());

        // WithTrashed query preserves audit trail
        $preserved = TelegramMessage::withTrashed()->where('peer_id', 123456)->where('id', 999)->first();
        $this->assertNotNull($preserved);
        $this->assertNotNull($preserved->deleted_at);
        $this->assertEquals('Hello Laravel Telegram Mirror', $preserved->message);
    }
}
