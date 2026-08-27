<?php

declare(strict_types=1);

namespace MeRezaRezaei\LaravelTelegram\Tests;

use PHPUnit\Framework\TestCase;
use MeRezaRezaei\LaravelTelegram\Services\TelegramClient;

class TelegramClientTest extends TestCase
{
    public function testSingleTenantUserWithConfigDefaults(): void
    {
        $client = new TelegramClient(
            defaultApiId: 12345,
            defaultApiHash: 'default_hash_123'
        );

        $userScope = $client->user(
            accountId: 987654321,
            authKey: random_bytes(256),
            dcId: 2
        );

        $result = $userScope->sendMessage(peer: '@durov', text: 'Hello Single Tenant!');

        $this->assertEquals('rpc_result', $result['_']);
        $this->assertEquals('messages.sendMessage', $result['method']);
        $this->assertEquals(12345, $userScope->mtproto->apiId);
        $this->assertEquals('default_hash_123', $userScope->mtproto->apiHash);
    }

    public function testMultiTenantUserWithRuntimeCredentials(): void
    {
        $client = new TelegramClient();

        // Custom account 1
        $user1 = $client->user(
            accountId: 111,
            authKey: random_bytes(256),
            dcId: 2,
            apiId: 77777,
            apiHash: 'custom_hash_account_1'
        );

        // Custom account 2 with different API ID and hash
        $user2 = $client->user(
            accountId: 222,
            authKey: random_bytes(256),
            dcId: 4,
            apiId: 88888,
            apiHash: 'custom_hash_account_2'
        );

        $this->assertEquals(77777, $user1->mtproto->apiId);
        $this->assertEquals('custom_hash_account_1', $user1->mtproto->apiHash);
        $this->assertEquals(2, $user1->session->dcId);

        $this->assertEquals(88888, $user2->mtproto->apiId);
        $this->assertEquals('custom_hash_account_2', $user2->mtproto->apiHash);
        $this->assertEquals(4, $user2->session->dcId);
    }

    public function testBotAccountWithDynamicToken(): void
    {
        $client = new TelegramClient(defaultBotToken: 'default:bot_token');

        // Bot with default token
        $defaultBot = $client->bot();
        $this->assertEquals('default:bot_token', $defaultBot->botToken);

        // Bot with dynamic token
        $customBot = $client->bot('999999:CUSTOM-BOT-TOKEN');
        $this->assertEquals('999999:CUSTOM-BOT-TOKEN', $customBot->botToken);

        $res = $customBot->sendMessage('@chat', 'Bot announcement');
        $this->assertTrue($res['ok']);
        $this->assertEquals('sendMessage', $res['result']['method']);
        $this->assertEquals('@chat', $res['result']['params']['chat_id']);
    }

    public function testUserWithoutApiCredentialsThrowsException(): void
    {
        $client = new TelegramClient(); // No defaults

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram API ID and API Hash are required');

        $client->user(accountId: 12345, authKey: random_bytes(256));
    }
}
