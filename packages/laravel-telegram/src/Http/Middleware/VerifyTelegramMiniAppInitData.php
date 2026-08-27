<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramMiniAppInitData
{
    /**
     * Handle an incoming request from Telegram Mini App.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->header('X-Telegram-Init-Data') ?? $request->input('initData');
        if (!$initData || !is_string($initData)) {
            return response()->json(['error' => 'Missing Telegram initData'], 401);
        }

        $botToken = config('telegram.bot_token') ?? env('TELEGRAM_BOT_TOKEN');
        if (!$botToken) {
            return response()->json(['error' => 'Bot token not configured on server'], 500);
        }

        $validatedUser = $this->validateInitData($initData, $botToken);
        if (!$validatedUser) {
            return response()->json(['error' => 'Invalid Telegram HMAC signature'], 403);
        }

        // Attach verified Telegram user to request attributes
        $request->attributes->set('telegram_user', $validatedUser);

        return $next($request);
    }

    /**
     * Cryptographically validates Telegram Mini App initData according to Telegram docs.
     */
    public function validateInitData(string $initData, string $botToken): ?array
    {
        parse_str($initData, $params);
        if (!isset($params['hash']) || !is_string($params['hash'])) {
            return null;
        }

        $hash = $params['hash'];
        unset($params['hash']);

        // Sort parameters alphabetically
        ksort($params);

        $dataCheckArr = [];
        foreach ($params as $k => $v) {
            $dataCheckArr[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, $hash)) {
            return null;
        }

        // Return parsed user object
        if (isset($params['user']) && is_string($params['user'])) {
            return json_decode($params['user'], true) ?: null;
        }

        return $params;
    }
}
