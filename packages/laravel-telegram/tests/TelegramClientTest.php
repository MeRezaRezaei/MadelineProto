<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\Services\TelegramClient;
use PHPUnit\Framework\TestCase;

class TelegramClientTest extends TestCase
{
    public function testTelegramClientCallsMethodWithSession(): void
    {
        $client = new TelegramClient(apiId: 12345, apiHash: 'sample_api_hash');

        $bound = $client->forAccount(
            accountId: 987654321,
            authKey: random_bytes(256),
            dcId: 2
        );

        $result = $bound->sendMessage(peer: '@durov', text: 'Hello Telegram!');

        $this->assertEquals('rpc_result', $result['_']);
        $this->assertEquals('messages.sendMessage', $result['method']);
        $this->assertEquals('@durov', $result['params']['peer']);
        $this->assertEquals('Hello Telegram!', $result['params']['message']);
    }

    public function testTelegramClientWithoutSessionThrowsException(): void
    {
        $client = new TelegramClient(apiId: 12345, apiHash: 'sample_api_hash');

        $this->expectException(\RuntimeException::class);
        $client->call('users.getFullUser', ['id' => 123]);
    }
}
