<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Database\Postgres;

/**
 * Real TelegramGateway backed by the logged-in MAIN (user) account's API.
 * The same account creates the channel/bot and performs the uploads; the bot
 * token is stored only so external (Laravel/Bot API) clients can also PUT.
 */
final class MtProtoGateway implements TelegramGateway
{
    private API $api;

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    private static function channelPeer(int $channelId): int
    {
        return -(1000000000000 + $channelId);
    }

    public function createChannel(string $title, string $about): array
    {
        $upd = $this->api->channels->createChannel([
            'broadcast' => true,
            'title' => $title,
            'about' => $about,
        ]);
        foreach (($upd['chats'] ?? []) as $chat) {
            if (($chat['_'] ?? '') === 'channel' && isset($chat['id'])) {
                return ['id' => (int) $chat['id'], 'access_hash' => (int) ($chat['access_hash'] ?? 0)];
            }
        }
        throw new \RuntimeException('createChannel returned no channel');
    }

    public function createBotViaBotFather(string $displayName, string $botUsername): string
    {
        $this->api->messages->sendMessage(['peer' => '@BotFather', 'message' => '/newbot']);
        $this->api->messages->sendMessage(['peer' => '@BotFather', 'message' => $displayName]);
        $this->api->messages->sendMessage(['peer' => '@BotFather', 'message' => $botUsername]);
        // BotFather confirms with a message containing the token.
        $history = $this->api->messages->getHistory(['peer' => '@BotFather', 'limit' => 3]);
        foreach (array_reverse($history['messages'] ?? []) as $m) {
            if (preg_match('/token[^\n]*?:\s*([\w:-]+)/i', $m['message'] ?? '', $mm)) {
                return $mm[1];
            }
        }
        throw new \RuntimeException('BotFather did not return a token');
    }

    public function addBotToChannel(int $channelId, string $botUsername): void
    {
        $peer = self::channelPeer($channelId);
        $this->api->channels->inviteToChannel(['channel' => $peer, 'users' => [$botUsername]]);
        $this->api->channels->editAdmin([
            'channel' => $peer,
            'user_id' => $botUsername,
            'admin_rights' => ['_' => 'chatAdminRights', 'post_messages' => true, 'change_info' => false, 'delete_messages' => false, 'ban_users' => false, 'invite_users' => false, 'pin_messages' => false, 'add_admins' => false, 'anonymous' => false, 'manage_call' => false, 'other' => false],
            'rank' => 'backup',
        ]);
    }

    public function sendDocument(int $channelId, string $partPath, int $index, int $total): int
    {
        $msg = $this->api->sendDocument(
            self::channelPeer($channelId),
            new \danog\MadelineProto\LocalFile($partPath),
            null,
            sprintf('part %d/%d', $index, $total),
            \danog\MadelineProto\ParseMode::TEXT,
            null,
            null,
            null,
            basename($partPath)
        );
        return (int) ($msg->getId() ?? 0);
    }

    public function getLatestMessageId(int $channelId): ?int
    {
        $history = $this->api->messages->getHistory(['peer' => self::channelPeer($channelId), 'limit' => 1]);
        $messages = $history['messages'] ?? [];
        return isset($messages[0]['id']) ? (int) $messages[0]['id'] : null;
    }

    public function sendMessageToPeer(int|string $peer, string $text): int
    {
        $upd = $this->api->messages->sendMessage(['peer' => $peer, 'message' => $text]);
        return (int) ($upd['id'] ?? 0);
    }
}
