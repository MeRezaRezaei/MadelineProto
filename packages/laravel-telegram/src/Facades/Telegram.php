<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Facades;

use Danog\LaravelTelegram\Services\TelegramClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static TelegramClient forAccount(int $accountId, ?string $authKey = null, int $dcId = 2)
 * @method static array call(string $method, array $params = [])
 * @method static array sendMessage(int|string $peer, string $text, array $options = [])
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
