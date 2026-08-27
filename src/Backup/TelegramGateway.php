<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

/**
 * All Telegram network I/O for the backup sink. Injected so the pipeline is
 * testable without a live account.
 */
interface TelegramGateway
{
    /** Create a private broadcast channel; returns ['id' => int, 'access_hash' => int]. */
    public function createChannel(string $title, string $about): array;

    /** Drive BotFather to create a bot; returns the bot token string. */
    public function createBotViaBotFather(string $displayName, string $botUsername): string;

    /** Give the bot post rights in the channel. */
    public function addBotToChannel(int $channelId, string $botUsername): void;

    /** Upload one archive part to the channel; returns the Telegram message id. */
    public function sendDocument(int $channelId, string $partPath, int $index, int $total): int;

    /** Latest message id in the channel, or null if empty. */
    public function getLatestMessageId(int $channelId): ?int;

    /** Send a text message to any peer (used for alerts). Returns message id. */
    public function sendMessageToPeer(int|string $peer, string $text): int;
}
