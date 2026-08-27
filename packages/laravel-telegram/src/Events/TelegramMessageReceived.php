<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Events;

use Danog\LaravelTelegram\Models\TelegramMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramMessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TelegramMessage $message,
        public int $accountId
    ) {}
}
