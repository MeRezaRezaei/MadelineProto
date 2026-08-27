<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

final class BackupBucket
{
    public function __construct(
        public int $id,
        public string $name,
        public int $channelId,
        public ?string $botToken,
        public ?string $alertPeer,
        public int $checkInterval,
        public int $staleAfter,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['channel_id'],
            $row['bot_token'] ?? null,
            $row['alert_peer'] ?? null,
            (int) ($row['check_interval'] ?? 900),
            (int) ($row['stale_after'] ?? 3900),
        );
    }
}
