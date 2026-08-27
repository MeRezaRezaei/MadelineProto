<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\MTProto\Client as MTProtoClient;
use Danog\LaravelTelegram\MTProto\SessionData;
use RuntimeException;

/**
 * High-level Laravel Telegram Client Manager.
 * Supports multi-tenant runtime API credentials, user sessions, and bot accounts.
 */
class TelegramClient
{
    public function __construct(
        public int $defaultApiId = 0,
        public string $defaultApiHash = '',
        public ?string $defaultBotToken = null,
        public ?array $defaultProxyConfig = null
    ) {}

    /**
     * Create or bind an MTProto user account session.
     *
     * @param int $accountId Telegram user ID
     * @param string|null $authKey Decrypted raw MTProto AuthKey
     * @param int $dcId Primary DC ID (default: 2)
     * @param int|null $apiId Custom runtime API ID (falls back to default if null)
     * @param string|null $apiHash Custom runtime API Hash (falls back to default if null)
     * @param array|null $proxyConfig Custom runtime proxy config (falls back to default if null)
     */
    public function user(
        int $accountId,
        ?string $authKey = null,
        int $dcId = 2,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        $finalApiId = $apiId ?? $this->defaultApiId;
        $finalApiHash = $apiHash ?? $this->defaultApiHash;
        $finalProxy = $proxyConfig ?? $this->defaultProxyConfig;

        if (empty($finalApiId) || empty($finalApiHash)) {
            throw new RuntimeException("Telegram API ID and API Hash are required. Pass them to user() or configure defaults in config/telegram.php.");
        }

        $session = new SessionData(
            dcId: $dcId,
            authKey: $authKey ?? '',
            userId: $accountId
        );

        $mtproto = new MTProtoClient(
            apiId: $finalApiId,
            apiHash: $finalApiHash,
            session: $session
        );

        if ($finalProxy) {
            $mtproto->setProxy($finalProxy);
        }

        return new UserAccountScope($mtproto, $session);
    }

    /**
     * Backward-compatible alias for user().
     */
    public function forAccount(
        int $accountId,
        ?string $authKey = null,
        int $dcId = 2,
        ?int $apiId = null,
        ?string $apiHash = null,
        ?array $proxyConfig = null
    ): UserAccountScope {
        return $this->user($accountId, $authKey, $dcId, $apiId, $apiHash, $proxyConfig);
    }

    /**
     * Create or bind a Bot API client.
     *
     * @param string|null $botToken Custom runtime Bot Token (falls back to default if null)
     * @param array|null $proxyConfig Custom runtime proxy
     */
    public function bot(?string $botToken = null, ?array $proxyConfig = null): BotClient
    {
        $finalToken = $botToken ?? $this->defaultBotToken;
        if (empty($finalToken)) {
            throw new RuntimeException("Telegram Bot Token is required. Pass it to bot() or configure TELEGRAM_BOT_TOKEN in .env.");
        }

        return new BotClient($finalToken, $proxyConfig ?? $this->defaultProxyConfig);
    }
}
