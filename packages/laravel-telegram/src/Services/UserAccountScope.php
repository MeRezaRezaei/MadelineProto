<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\MTProto\Client as MTProtoClient;
use Danog\LaravelTelegram\MTProto\SessionData;

/**
 * Scoped User Account operations.
 */
class UserAccountScope
{
    public function __construct(
        public MTProtoClient $mtproto,
        public SessionData $session
    ) {}

    /**
     * Executes any raw MTProto method (e.g. 'messages.sendMessage', 'users.getFullUser').
     */
    public function call(string $method, array $params = []): array
    {
        return $this->mtproto->call($method, $params);
    }

    /**
     * Send a text message to any user, group, or channel.
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
