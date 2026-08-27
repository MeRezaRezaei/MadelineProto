<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramMessageDeleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param list<int> $messageIds
     */
    public function __construct(
        public int $peerId,
        public array $messageIds,
        public int $accountId
    ) {}
}
