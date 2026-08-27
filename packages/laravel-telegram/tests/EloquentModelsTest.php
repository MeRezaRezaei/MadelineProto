<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\Models\TelegramAccount;
use Danog\LaravelTelegram\Models\TelegramChannel;
use Danog\LaravelTelegram\Models\TelegramChat;
use Danog\LaravelTelegram\Models\TelegramDialog;
use Danog\LaravelTelegram\Models\TelegramMessage;
use Danog\LaravelTelegram\Models\TelegramPeer;
use Danog\LaravelTelegram\Models\TelegramUser;
use Illuminate\Support\Carbon;

class EloquentModelsTest extends TestCase
{
    public function testTelegramAccountCreationAndRelationships(): void
    {
        $account = TelegramAccount::create([
            'id' => 123456789,
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'phone' => '+1234567890',
            'api_id' => 99999,
            'api_hash' => 'hash12345',
            'auth_state' => 'active',
            'settings' => ['theme' => 'dark'],
        ]);

        $this->assertNotNull($account);
        $this->assertEquals(123456789, $account->id);
        $this->assertTrue($account->isActive());
        $this->assertEquals(['theme' => 'dark'], $account->settings);

        // Add dialog
        $dialog = TelegramDialog::create([
            'account_id' => $account->id,
            'peer_id' => -1001987654321,
            'top_message_id' => 50,
            'unread_count' => 2,
        ]);

        $this->assertCount(1, $account->dialogs);
        $this->assertEquals($account->id, $dialog->account->id);
    }

    public function testTelegramUserAndMessageWithSoftDeletes(): void
    {
        $user = TelegramUser::create([
            'id' => 555444333,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'username' => 'alicesmith',
            'is_premium' => true,
        ]);

        $this->assertEquals('Alice Smith', $user->full_name);

        $msg = TelegramMessage::create([
            'id' => 101,
            'peer_id' => $user->id,
            'from_id' => $user->id,
            'date' => Carbon::now(),
            'message' => 'Critical confidential message',
            'media_type' => 'document',
            'media_hash' => 'sha256_mock_hash',
        ]);

        $this->assertNull($msg->deleted_at);

        // Soft Delete
        $msg->delete();

        // Standard query hides soft-deleted message
        $this->assertNull(TelegramMessage::where('peer_id', $user->id)->where('id', 101)->first());

        // WithTrashed query preserves historical audit record
        $archived = TelegramMessage::withTrashed()->where('peer_id', $user->id)->where('id', 101)->first();
        $this->assertNotNull($archived);
        $this->assertNotNull($archived->deleted_at);
        $this->assertEquals('Critical confidential message', $archived->message);
    }
}
