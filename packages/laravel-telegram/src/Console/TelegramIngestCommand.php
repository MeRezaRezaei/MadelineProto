<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Console;

use Danog\LaravelTelegram\Services\TelegramIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class TelegramIngestCommand extends Command
{
    protected $signature = 'telegram:ingest {--group=laravel-workers} {--consumer=worker-1}';
    protected $description = 'Ingests Telegram updates continuously from Redis Stream into PostgreSQL mirror';

    public function handle(TelegramIngestService $ingestService): int
    {
        $this->info("Starting Telegram Redis Stream Ingest Worker...");
        $stream = 'tg:stream:updates';
        $group = (string)$this->option('group');
        $consumer = (string)$this->option('consumer');

        // Create consumer group if not exists
        try {
            Redis::xgroup('CREATE', $stream, $group, '0', true);
        } catch (\Throwable) {
            // Group might already exist
        }

        while (true) {
            // Read next messages from Redis Stream with 2-second block
            $entries = Redis::xreadgroup($group, $consumer, [$stream => '>'], 50, 2000);

            if (!empty($entries[$stream])) {
                foreach ($entries[$stream] as $id => $payload) {
                    $accountId = (int)($payload['account_id'] ?? 0);
                    $update = json_decode($payload['data'] ?? '{}', true);

                    if ($accountId && $update) {
                        $ingestService->ingestUpdate($accountId, $update);
                    }

                    // Acknowledge processed stream entry
                    Redis::xack($stream, $group, [$id]);
                }
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return 0;
    }
}
