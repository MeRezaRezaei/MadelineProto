<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\Models\TelegramAccount;
use Danog\LaravelTelegram\Models\TelegramMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class TelegramClient
{
    protected ?int $activeAccountId = null;

    public function forAccount(int $accountId): self
    {
        $clone = clone $this;
        $clone->activeAccountId = $accountId;
        return $clone;
    }

    /**
     * Send a text message to any user, chat, or channel.
     */
    public function sendMessage(int|string $peer, string $text, array $options = []): array
    {
        $this->ensureAccountSelected();

        $command = [
            'action' => 'messages.sendMessage',
            'account_id' => $this->activeAccountId,
            'peer' => $peer,
            'message' => $text,
            'options' => $options,
        ];

        Redis::rpush("tg:queue:commands:{$this->activeAccountId}", json_encode($command));

        return ['status' => 'queued', 'account_id' => $this->activeAccountId, 'peer' => $peer];
    }

    /**
     * Search message history across PostgreSQL mirror without hitting Telegram API rate limits.
     */
    public function searchLocalHistory(int $peerId, ?string $query = null, int $limit = 50): Collection
    {
        $builder = TelegramMessage::where('peer_id', $peerId)
            ->orderBy('date', 'desc')
            ->limit($limit);

        if ($query) {
            $builder->where('message', 'like', "%{$query}%");
        }

        return $builder->get();
    }

    protected function ensureAccountSelected(): void
    {
        if ($this->activeAccountId === null) {
            throw new RuntimeException("No Telegram account selected. Call forAccount(\$accountId) first.");
        }
    }
}
