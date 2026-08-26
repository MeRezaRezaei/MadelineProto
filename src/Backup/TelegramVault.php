<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    The MadelineProto Team
 * @copyright 2016-2025 The MadelineProto Team
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Backup;

use danog\MadelineProto\API;
use danog\MadelineProto\LocalFile;
use RuntimeException;
use Throwable;

final class TelegramVault implements VaultInterface
{
    public const CHANNEL_PREFIX = 'madeline-backup:';

    private API $api;

    public function __construct(API $api)
    {
        $this->api = $api;
    }

    /**
     * Boot a dedicated storage session.
     */
    public static function boot(string $sessionPath): self
    {
        $api = new API($sessionPath);
        $api->start();
        return new self($api);
    }

    /**
     * Detect Premium for 4 GB chunks: true iff $api->getSelf()['premium'] ?? false.
     */
    public function isPremium(): bool
    {
        $self = $this->api->getSelf();
        return (bool) ($self['premium'] ?? false);
    }

    /**
     * Interactive login helper used by CLI: phone number + code, then optional 2FA password.
     */
    public static function login(string $sessionPath, string $phone): self
    {
        $api = new API($sessionPath);
        $api->phoneLogin($phone);
        return new self($api);
    }

    public function ensureChannel(string $setId): int
    {
        $title = self::CHANNEL_PREFIX . $setId;
        try {
            $admined = $this->api->channels->getAdminedChannels();
            foreach ($admined['chats'] ?? [] as $chat) {
                if (($chat['title'] ?? '') === $title) {
                    return (int) $chat['id'];
                }
            }
        } catch (Throwable) {
            // Ignore error and try createChannel
        }

        $updates = $this->api->channels->createChannel([
            'title' => $title,
            'about' => 'MadelineProto backup storage',
            'broadcast' => true,
            'megagroup' => false,
        ]);

        if (isset($updates['chats'][0]['id'])) {
            return (int) $updates['chats'][0]['id'];
        }

        throw new RuntimeException("Failed to create backup channel for set {$setId}");
    }

    /**
     * @return array{0: int, 1: string} [msgId, fileId]
     */
    public function uploadChunk(int $channelId, string $name, string $tmpPath): array
    {
        $media = $this->api->uploadDocument(
            file: new LocalFile($tmpPath, $name),
            peer: $channelId,
            caption: $name,
            fileName: $name
        );

        $msgId = (int) ($media->id ?? $media['id'] ?? 0);
        $fileId = (string) ($media->fileId ?? $media['fileId'] ?? "tg:{$channelId}:{$msgId}");

        return [$msgId, $fileId];
    }

    public function uploadManifest(int $channelId, string $snapshotId, string $json): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest-') . '.json';
        file_put_contents($tmp, $json);
        $name = "manifest-{$snapshotId}.json";

        try {
            $media = $this->api->uploadDocument(
                file: new LocalFile($tmp, $name),
                peer: $channelId,
                caption: $name,
                fileName: $name
            );
            return (int) ($media->id ?? $media['id'] ?? 0);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function downloadChunk(int $channelId, int $msgId, string $destPath): void
    {
        $msgs = $this->api->channels->getMessages([
            'channel' => $channelId,
            'id' => [$msgId],
        ]);

        $msg = $msgs['messages'][0] ?? $msgs[0] ?? null;
        if (!$msg) {
            throw new RuntimeException("Message {$msgId} not found in channel {$channelId}");
        }

        $media = $msg['media'] ?? $msg->media ?? $msg;
        $this->api->downloadToFile($media, $destPath);
    }

    public function downloadManifest(int $channelId, int $msgId): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'manifest-dl-');
        try {
            $this->downloadChunk($channelId, $msgId, $tmp);
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new RuntimeException("Failed to read downloaded manifest for msg {$msgId}");
            }
            return $content;
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }
}
