<?php

declare(strict_types=1);

namespace Rezarezaei\LaravelTelegram\Services;

use RuntimeException;

class TelegramAuthService
{
    /**
     * Start phone login state.
     */
    public function startPhoneLogin(
        string $phone,
        int $apiId,
        string $apiHash
    ): array {
        return [
            'status' => 'pending_code',
            'phone' => $phone,
            'api_id' => $apiId,
            'api_hash' => $apiHash,
        ];
    }
}
