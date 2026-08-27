<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Services;

use Danog\LaravelTelegram\Events\TelegramMessageDeleted;
use Danog\LaravelTelegram\Events\TelegramMessageReceived;
use Danog\LaravelTelegram\Models\TelegramChannel;
use Danog\LaravelTelegram\Models\TelegramChat;
use Danog\LaravelTelegram\Models\TelegramDialog;
use Danog\LaravelTelegram\Models\TelegramMessage;
use Danog\LaravelTelegram\Models\TelegramPeer;
use Danog\LaravelTelegram\Models\TelegramUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TelegramIngestService
{
    /**
     * Ingests a decoded Telegram update coming from the Redis Stream.
     *
     * @param int $accountId The local Telegram account ID receiving the update
     * @param array<string, mixed> $update The deserialized TL update array
     */
    public function ingestUpdate(int $accountId, array $update): void
    {
        $type = $update['_'] ?? null;
        if (!$type) {
            return;
        }

        switch ($type) {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
                $this->handleNewMessage($accountId, $update['message'] ?? []);
                break;

            case 'updateDeleteMessages':
                $this->handleDeleteMessages($accountId, $update['messages'] ?? [], $update['peer_id'] ?? null);
                break;

            case 'updateDeleteChannelMessages':
                $channelId = $update['channel_id'] ?? null;
                $peerId = $channelId ? -(1000000000000 + (int)$channelId) : 0;
                $this->handleDeleteMessages($accountId, $update['messages'] ?? [], $peerId);
                break;

            case 'updateUser':
            case 'user':
                $this->upsertUser($update);
                break;

            case 'updateChat':
            case 'chat':
                $this->upsertChat($update);
                break;

            case 'updateChannel':
            case 'channel':
                $this->upsertChannel($update);
                break;
        }
    }

    /**
     * @param array<string, mixed> $msg
     */
    public function handleNewMessage(int $accountId, array $msg): ?TelegramMessage
    {
        if (empty($msg['id']) || !isset($msg['peer_id'])) {
            return null;
        }

        $id = (int)$msg['id'];
        $peerId = $this->extractPeerId($msg['peer_id']);
        $fromId = isset($msg['from_id']) ? $this->extractPeerId($msg['from_id']) : null;
        $date = isset($msg['date']) ? Carbon::createFromTimestamp($msg['date']) : Carbon::now();

        // Extract media info if present
        $mediaType = null;
        $mediaHash = null;
        $mediaMeta = null;
        if (!empty($msg['media'])) {
            $mediaType = $msg['media']['_'] ?? 'unknown';
            $mediaMeta = $msg['media'];
            if (!empty($msg['media']['document']['id'])) {
                $mediaHash = hash('sha256', (string)$msg['media']['document']['id']);
            } elseif (!empty($msg['media']['photo']['id'])) {
                $mediaHash = hash('sha256', (string)$msg['media']['photo']['id']);
            }
        }

        $message = TelegramMessage::updateOrCreate(
            ['peer_id' => $peerId, 'id' => $id],
            [
                'from_id' => $fromId,
                'date' => $date,
                'message' => $msg['message'] ?? null,
                'media_type' => $mediaType,
                'media_hash' => $mediaHash,
                'media_meta' => $mediaMeta,
                'reply_to_msg_id' => $msg['reply_to']['reply_to_msg_id'] ?? null,
                'reply_to_peer_id' => isset($msg['reply_to']['reply_to_peer_id']) ? $this->extractPeerId($msg['reply_to']['reply_to_peer_id']) : null,
                'entities' => $msg['entities'] ?? null,
                'views' => $msg['views'] ?? null,
                'forwards' => $msg['forwards'] ?? null,
                'is_outgoing' => (bool)($msg['out'] ?? false),
                'raw_attributes' => $msg,
            ]
        );

        // Update dialog top_message
        TelegramDialog::updateOrCreate(
            ['account_id' => $accountId, 'peer_id' => $peerId],
            ['top_message_id' => $id]
        );

        event(new TelegramMessageReceived($message, $accountId));

        return $message;
    }

    /**
     * Soft-deletes messages (sets deleted_at = NOW()) so Telegram deletions never destroy audit history.
     *
     * @param list<int> $messageIds
     */
    public function handleDeleteMessages(int $accountId, array $messageIds, ?int $peerId = null): void
    {
        if (empty($messageIds)) {
            return;
        }

        $query = TelegramMessage::whereIn('id', $messageIds);
        if ($peerId !== null && $peerId !== 0) {
            $query->where('peer_id', $peerId);
        }

        $query->update(['deleted_at' => Carbon::now()]);

        if ($peerId !== null) {
            event(new TelegramMessageDeleted($peerId, $messageIds, $accountId));
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function upsertUser(array $user): TelegramUser
    {
        $id = (int)$user['id'];
        $username = $user['username'] ?? null;
        $phone = $user['phone'] ?? null;

        $userModel = TelegramUser::updateOrCreate(
            ['id' => $id],
            [
                'access_hash' => isset($user['access_hash']) ? (string)$user['access_hash'] : null,
                'first_name' => $user['first_name'] ?? null,
                'last_name' => $user['last_name'] ?? null,
                'username' => $username,
                'phone' => $phone,
                'is_bot' => (bool)($user['bot'] ?? false),
                'is_verified' => (bool)($user['verified'] ?? false),
                'is_premium' => (bool)($user['premium'] ?? false),
                'status' => $user['status'] ?? null,
                'photo' => $user['photo'] ?? null,
                'raw_attributes' => $user,
            ]
        );

        TelegramPeer::updateOrCreate(
            ['peer_id' => $id],
            ['type' => 'user', 'username' => $username, 'phone' => $phone]
        );

        return $userModel;
    }

    /**
     * @param array<string, mixed> $chat
     */
    public function upsertChat(array $chat): TelegramChat
    {
        $id = (int)$chat['id'];
        $chatModel = TelegramChat::updateOrCreate(
            ['id' => $id],
            [
                'title' => $chat['title'] ?? '',
                'participants_count' => (int)($chat['participants_count'] ?? 0),
                'photo' => $chat['photo'] ?? null,
                'raw_attributes' => $chat,
            ]
        );

        TelegramPeer::updateOrCreate(
            ['peer_id' => -$id],
            ['type' => 'chat', 'username' => null, 'phone' => null]
        );

        return $chatModel;
    }

    /**
     * @param array<string, mixed> $channel
     */
    public function upsertChannel(array $channel): TelegramChannel
    {
        $id = (int)$channel['id'];
        $username = $channel['username'] ?? null;

        $channelModel = TelegramChannel::updateOrCreate(
            ['id' => $id],
            [
                'access_hash' => isset($channel['access_hash']) ? (string)$channel['access_hash'] : null,
                'title' => $channel['title'] ?? '',
                'username' => $username,
                'participants_count' => (int)($channel['participants_count'] ?? 0),
                'is_broadcast' => (bool)($channel['broadcast'] ?? false),
                'is_megagroup' => (bool)($channel['megagroup'] ?? false),
                'is_verified' => (bool)($channel['verified'] ?? false),
                'photo' => $channel['photo'] ?? null,
                'raw_attributes' => $channel,
            ]
        );

        $unifiedPeerId = -(1000000000000 + $id);
        TelegramPeer::updateOrCreate(
            ['peer_id' => $unifiedPeerId],
            ['type' => 'channel', 'username' => $username, 'phone' => null]
        );

        return $channelModel;
    }

    /**
     * Resolves TL Peer objects or integer IDs to unified peer ID integer.
     */
    public function extractPeerId(mixed $peer): int
    {
        if (is_int($peer)) {
            return $peer;
        }

        if (is_array($peer)) {
            if (isset($peer['user_id'])) {
                return (int)$peer['user_id'];
            }
            if (isset($peer['chat_id'])) {
                return -(int)$peer['chat_id'];
            }
            if (isset($peer['channel_id'])) {
                return -(1000000000000 + (int)$peer['channel_id']);
            }
        }

        return 0;
    }
}
