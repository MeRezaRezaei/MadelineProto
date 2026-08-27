<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\Models\TelegramAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;

class TelegramAuthService
{
    /**
     * Step 1: Initialize phone login and request SMS / Telegram code.
     */
    public function startPhoneLogin(
        string $phone,
        int $apiId,
        string $apiHash,
        ?string $userId = null
    ): TelegramAccount {
        // Pending synthetic account ID until Telegram returns user_id
        $syntheticId = -$apiId;

        $account = TelegramAccount::updateOrCreate(
            ['id' => $syntheticId],
            [
                'user_id' => $userId,
                'phone' => $phone,
                'api_id' => $apiId,
                'api_hash' => $apiHash,
                'auth_state' => 'pending_code',
            ]
        );

        // Dispatch outbound command to MTProto Daemon via Redis Queue
        $command = [
            'action' => 'auth.sendCode',
            'account_id' => $syntheticId,
            'phone' => $phone,
            'api_id' => $apiId,
            'api_hash' => $apiHash,
        ];
        Redis::rpush("tg:queue:commands:{$syntheticId}", json_encode($command));

        return $account;
    }

    /**
     * Step 2: Submit phone verification code.
     */
    public function submitCode(int $accountId, string $code): array
    {
        $account = TelegramAccount::findOrFail($accountId);
        if ($account->auth_state !== 'pending_code') {
            throw new InvalidArgumentException("Account is not in 'pending_code' state.");
        }

        // Outbound command to MTProto worker
        $command = [
            'action' => 'auth.signIn',
            'account_id' => $accountId,
            'code' => $code,
        ];
        Redis::rpush("tg:queue:commands:{$accountId}", json_encode($command));

        return [
            'status' => 'submitted',
            'account_id' => $accountId,
        ];
    }

    /**
     * Step 3: Submit 2FA Cloud Password (SRP verification).
     */
    public function submit2faPassword(int $accountId, string $password): array
    {
        $account = TelegramAccount::findOrFail($accountId);
        if ($account->auth_state !== 'pending_2fa') {
            throw new InvalidArgumentException("Account is not in 'pending_2fa' state.");
        }

        $command = [
            'action' => 'auth.checkPassword',
            'account_id' => $accountId,
            'password' => $password,
        ];
        Redis::rpush("tg:queue:commands:{$accountId}", json_encode($command));

        return [
            'status' => 'submitted',
            'account_id' => $accountId,
        ];
    }

    /**
     * Callback invoked when MTProto daemon confirms successful login.
     */
    public function onLoginSuccess(int $pendingAccountId, int $telegramUserId, string $rawSessionString): TelegramAccount
    {
        $pendingAccount = TelegramAccount::find($pendingAccountId);
        $userId = $pendingAccount?->user_id;
        $phone = $pendingAccount?->phone;
        $apiId = $pendingAccount?->api_id;
        $apiHash = $pendingAccount?->api_hash;

        if ($pendingAccount && $pendingAccountId < 0) {
            $pendingAccount->delete(); // Remove temporary placeholder
        }

        return TelegramAccount::updateOrCreate(
            ['id' => $telegramUserId],
            [
                'user_id' => $userId,
                'phone' => $phone,
                'api_id' => $apiId,
                'api_hash' => $apiHash,
                'auth_state' => 'active',
                'session_key_encrypted' => Crypt::encryptString($rawSessionString),
            ]
        );
    }
}
