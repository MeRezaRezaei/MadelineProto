<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Facades;

use Danog\LaravelTelegram\Services\BotClient;
use Danog\LaravelTelegram\Services\TelegramClient;
use Danog\LaravelTelegram\Services\UserAccountScope;
use Illuminate\Support\Facades\Facade;

/**
 * @method static UserAccountScope user(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static UserAccountScope forAccount(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static BotClient bot(?string $botToken = null, ?array $proxyConfig = null)
 *
 * @see \Danog\LaravelTelegram\Services\TelegramClient
 */
class Telegram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramClient::class;
    }
}
