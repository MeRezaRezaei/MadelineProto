<?php

declare(strict_types=1);

namespace Rezarezaei\LaravelTelegram\Facades;

use Illuminate\Support\Facades\Facade;
use Rezarezaei\LaravelTelegram\Services\BotClient;
use Rezarezaei\LaravelTelegram\Services\TelegramClient;
use Rezarezaei\LaravelTelegram\Services\UserAccountScope;

/**
 * @method static UserAccountScope user(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static UserAccountScope forAccount(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static BotClient bot(?string $botToken = null, ?array $proxyConfig = null)
 *
 * @see \Rezarezaei\LaravelTelegram\Services\TelegramClient
 */
class Telegram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramClient::class;
    }
}
