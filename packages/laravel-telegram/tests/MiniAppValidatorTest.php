<?php

declare(strict_types=1);

namespace Rezarezaei\LaravelTelegram\Tests;

use PHPUnit\Framework\TestCase;
use Rezarezaei\LaravelTelegram\Http\Middleware\VerifyTelegramMiniAppInitData;

class MiniAppValidatorTest extends TestCase
{
    public function testValidTelegramMiniAppInitDataHmac(): void
    {
        $botToken = '123456789:ABCdefGHIjklMNOpqrsTUVwxyz1234567';
        $userJson = json_encode([
            'id' => 987654321,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'username' => 'johndoe',
            'language_code' => 'en',
        ]);

        $params = [
            'auth_date' => (string)time(),
            'query_id' => 'AAHdF6IQAAAAAN0XohBSnE5c',
            'user' => $userJson,
        ];
        ksort($params);

        $dataCheckArr = [];
        foreach ($params as $k => $v) {
            $dataCheckArr[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $initData = http_build_query(array_merge($params, ['hash' => $hash]));

        $middleware = new VerifyTelegramMiniAppInitData();
        $validated = $middleware->validateInitData($initData, $botToken);

        $this->assertNotNull($validated);
        $this->assertEquals(987654321, $validated['id']);
        $this->assertEquals('johndoe', $validated['username']);
    }

    public function testTamperedInitDataFailsHmac(): void
    {
        $botToken = '123456789:ABCdefGHIjklMNOpqrsTUVwxyz1234567';
        $tamperedInitData = 'auth_date=1700000000&query_id=fake_query&user=%7B%22id%22%3A1%7D&hash=invalid_hash_value';

        $middleware = new VerifyTelegramMiniAppInitData();
        $validated = $middleware->validateInitData($tamperedInitData, $botToken);

        $this->assertNull($validated);
    }
}
