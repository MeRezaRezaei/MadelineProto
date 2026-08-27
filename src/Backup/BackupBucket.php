<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

/**
 * Value object describing a provisioned backup bucket.
 */
final class BackupBucket
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $channelId,
        public readonly string $channelTitle,
        public readonly ?string $botToken,
        public readonly ?string $botUsername,
        public readonly string $alertPeer,
        public readonly int $checkInterval,
        public readonly int $staleAfter,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            channelId: (int) $row['channel_id'],
            channelTitle: (string) $row['channel_title'],
            botToken: $row['bot_token'] !== null ? (string) $row['bot_token'] : null,
            botUsername: $row['bot_username'] !== null ? (string) $row['bot_username'] : null,
            alertPeer: (string) $row['alert_peer'],
            checkInterval: (int) $row['check_interval'],
            staleAfter: (int) $row['stale_after'],
        );
    }
}
