<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\MTProto\Client as MTProtoClient;
use Danog\LaravelTelegram\MTProto\SessionData;
use RuntimeException;

/**
 * High-level Laravel Telegram Client.
 * Provides fluent multi-account MTProto RPC calls and proxy routing.
 */
class TelegramClient
{
    protected ?MTProtoClient $mtproto = null;
    protected ?SessionData $session = null;

    public function __construct(
        public int $apiId = 0,
        public string $apiHash = '',
        public ?array $proxyConfig = null
    ) {}

    /**
     * Bind a specific Telegram account session to this client instance.
     *
     * @param int $accountId Telegram user ID
     * @param string|null $authKey Decrypted raw MTProto AuthKey string
     * @param int $dcId Primary DC ID
     */
    public function forAccount(int $accountId, ?string $authKey = null, int $dcId = 2): self
    {
        $clone = clone $this;
        $clone->session = new SessionData(
            dcId: $dcId,
            authKey: $authKey ?? '',
            userId: $accountId
        );

        $clone->mtproto = new MTProtoClient(
            apiId: $this->apiId,
            apiHash: $this->apiHash,
            session: $clone->session
        );

        if ($this->proxyConfig) {
            $clone->mtproto->setProxy($this->proxyConfig);
        }

        return $clone;
    }

    /**
     * Executes any Telegram MTProto method directly (e.g. 'messages.sendMessage', 'users.getFullUser').
     */
    public function call(string $method, array $params = []): array
    {
        if ($this->mtproto === null) {
            throw new RuntimeException("No Telegram account bound. Call forAccount(\$accountId, \$authKey) first.");
        }

        return $this->mtproto->call($method, $params);
    }

    /**
     * Convenient shortcut for sending text messages.
     */
    public function sendMessage(int|string $peer, string $text, array $options = []): array
    {
        return $this->call('messages.sendMessage', array_merge([
            'peer' => $peer,
            'message' => $text,
            'random_id' => random_int(1, PHP_INT_MAX),
        ], $options));
    }
}
