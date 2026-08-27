<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

/**
 * Immutable value object for a backup bucket row.
 */
final class BackupBucket
{
    public function __construct(
        public int $id,
        public string $name,
        public int $channelId,
        public ?string $channelTitle,
        public ?string $botToken,
        public ?string $botUsername,
        public ?string $alertPeer,
        public int $checkInterval,
        public int $staleAfter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            channelId: (int) ($row['channel_id'] ?? 0),
            channelTitle: $row['channel_title'] ?? null,
            botToken: $row['bot_token'] ?? null,
            botUsername: $row['bot_username'] ?? null,
            alertPeer: $row['alert_peer'] ?? null,
            checkInterval: (int) ($row['check_interval'] ?? 900),
            staleAfter: (int) ($row['stale_after'] ?? 3900),
        );
    }
}
