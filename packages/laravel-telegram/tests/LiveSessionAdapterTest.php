<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Tests;

use Danog\LaravelTelegram\MTProto\Client;
use Danog\LaravelTelegram\MTProto\SessionData;
use PHPUnit\Framework\TestCase;

class LiveSessionAdapterTest extends TestCase
{
    public function testSessionAdapterLoadsFromDatabaseRecord(): void
    {
        // Sample DB record as stored by Laravel / SQLite
        $accountRecord = [
            'id' => 123456789,
            'api_id' => 11111,
            'api_hash' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
            'dc_id' => 2,
            'auth_key' => base64_encode(str_repeat("\x42", 256)),
            'server_time_delta' => -10,
            'seq_no' => 4,
        ];

        $session = SessionData::fromArray($accountRecord);

        $client = new Client(
            apiId: $accountRecord['api_id'],
            apiHash: $accountRecord['api_hash'],
            session: $session
        );

        $this->assertEquals(2, $client->getSession()->dcId);
        $this->assertEquals(str_repeat("\x42", 256), $client->getSession()->authKey);
        $this->assertEquals(-10, $client->getSession()->serverTimeDelta);

        // Perform RPC call with session
        $response = $client->call('help.getConfig');
        $this->assertEquals('rpc_result', $response['_']);
        $this->assertEquals('help.getConfig', $response['method']);
        $this->assertEquals(2, $response['dc_id']);
    }

    public function testDirectTcpSocketToTelegramDc(): void
    {
        $dcIp = Client::DC_IPS[2]; // 149.154.167.51
        $port = Client::DEFAULT_PORT; // 443

        $socket = @fsockopen($dcIp, $port, $errno, $errstr, 3.0);
        if ($socket) {
            $this->assertIsResource($socket);
            fclose($socket);
        } else {
            $this->markTestSkipped("Direct internet TCP access to Telegram DC ({$dcIp}:{$port}) unavailable in test environment.");
        }
    }
}
