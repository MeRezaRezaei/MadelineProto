<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram;

use Danog\LaravelTelegram\Http\Middleware\VerifyTelegramMiniAppInitData;
use Danog\LaravelTelegram\Services\TelegramAuthService;
use Danog\LaravelTelegram\Services\TelegramClient;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/telegram.php', 'telegram');

        $this->app->singleton(TelegramClient::class, function ($app) {
            $config = $app['config']['telegram'] ?? [];
            return new TelegramClient(
                apiId: (int)($config['api_id'] ?? 0),
                apiHash: (string)($config['api_hash'] ?? ''),
                proxyConfig: $config['proxy'] ?? null
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
