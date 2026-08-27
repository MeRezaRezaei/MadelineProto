<?php

declare(strict_types=1);

namespace MeRezaRezaei\LaravelTelegram\Facades;

use Illuminate\Support\Facades\Facade;
use MeRezaRezaei\LaravelTelegram\Services\BotClient;
use MeRezaRezaei\LaravelTelegram\Services\TelegramClient;
use MeRezaRezaei\LaravelTelegram\Services\UserAccountScope;

/**
 * @method static UserAccountScope user(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static UserAccountScope forAccount(int $accountId, ?string $authKey = null, int $dcId = 2, ?int $apiId = null, ?string $apiHash = null, ?array $proxyConfig = null)
 * @method static BotClient bot(?string $botToken = null, ?array $proxyConfig = null)
 *
 * @see \MeRezaRezaei\LaravelTelegram\Services\TelegramClient
 */
class Telegram extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TelegramClient::class;
    }
}
