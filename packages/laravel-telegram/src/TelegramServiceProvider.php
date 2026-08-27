<?php

declare(strict_types=1);

namespace Rezarezaei\LaravelTelegram;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Rezarezaei\LaravelTelegram\Http\Middleware\VerifyTelegramMiniAppInitData;
use Rezarezaei\LaravelTelegram\Services\TelegramAuthService;
use Rezarezaei\LaravelTelegram\Services\TelegramClient;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/telegram.php', 'telegram');

        $this->app->singleton(TelegramClient::class, function ($app) {
            $config = $app['config']['telegram'] ?? [];
            return new TelegramClient(
                defaultApiId: (int)($config['api_id'] ?? 0),
                defaultApiHash: (string)($config['api_hash'] ?? ''),
                defaultBotToken: $config['default_bot_token'] ?? null,
                defaultProxyConfig: $config['proxy'] ?? null
            );
        });

        $this->app->singleton(TelegramAuthService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/telegram.php' => config_path('telegram.php'),
            ], 'telegram-config');
        }

        if (isset($this->app['router'])) {
            /** @var Router $router */
            $router = $this->app['router'];
            $router->aliasMiddleware('tg.miniapp', VerifyTelegramMiniAppInitData::class);
        }
    }
}
