<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

final class AlertSender
{
    public function __construct(private TelegramGateway $gw)
    {
    }

    public function alert(BackupBucket $bucket, string $reason): void
    {
        $peer = $bucket->alertPeer ?: 'me';
        $text = sprintf("[madeline-backup] ALERT bucket=%s: %s", $bucket->name, $reason);
        $this->gw->sendMessageToPeer($peer, $text);
    }
}
