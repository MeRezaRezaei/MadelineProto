<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram;

use Danog\LaravelTelegram\Console\TelegramIngestCommand;
use Danog\LaravelTelegram\Http\Middleware\VerifyTelegramMiniAppInitData;
use Danog\LaravelTelegram\Services\TelegramAuthService;
use Danog\LaravelTelegram\Services\TelegramClient;
use Danog\LaravelTelegram\Services\TelegramIngestService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/telegram.php', 'telegram');

        $this->app->singleton(TelegramClient::class);
        $this->app->singleton(TelegramIngestService::class);
        $this->app->singleton(TelegramAuthService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                TelegramIngestCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/telegram.php' => config_path('telegram.php'),
            ], 'telegram-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'telegram-migrations');
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('tg.miniapp', VerifyTelegramMiniAppInitData::class);
    }
}
