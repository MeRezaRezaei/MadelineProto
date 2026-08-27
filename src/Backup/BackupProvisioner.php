<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\RelationalStore;
use RuntimeException;

final class BackupProvisioner
{
    public function __construct(
        private RelationalStore $store,
        private TelegramGateway $gw,
    ) {
    }

    public function provision(string $name, ?string $alertPeer = null): BackupBucket
    {
        if ($this->store->getBackupBucket($name) !== null) {
            throw new RuntimeException("Bucket already exists: {$name}");
        }
        $rand = substr(md5((string) mt_rand()), 0, 10);
        $channelTitle = 'madeline-gather-' . $rand;
        $botUsername = 'madeline_gather_' . $rand . '_bot';

        $channel = $this->gw->createChannel($channelTitle, 'MadelineProto backup sink');
        $token = $this->gw->createBotViaBotFather($channelTitle . ' bot', $botUsername);
        $this->gw->addBotToChannel($channel['id'], $botUsername);

        $this->store->upsertBackupBucket([
            'name' => $name,
            'channel_id' => $channel['id'],
            'channel_title' => $channelTitle,
            'bot_token' => $token,
            'bot_username' => $botUsername,
            'alert_peer' => $alertPeer ?? '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);

        $row = $this->store->getBackupBucket($name);
        return BackupBucket::fromRow($row);
    }
}
