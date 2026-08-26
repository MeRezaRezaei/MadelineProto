<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU Affero General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Sync;

use danog\MadelineProto\EventHandler;

/**
 * Live-feed bridge: a MadelineProto EventHandler that normalizes raw Telegram
 * updates and forwards them to an UpdateProcessor (UpdateHandler).
 *
 * MadelineProto instantiates the handler via newInstanceWithoutConstructor(),
 * so it cannot take constructor dependencies. State is injected out-of-band via
 * the static configure() method before startAndLoop(), matching the established
 * MadelineProto handler pattern.
 */
final class TgUpdateForwarder extends EventHandler
{
    private static ?UpdateProcessor $handler = null;
    private static int $accountId = 0;

    public static function configure(UpdateProcessor $handler, int $accountId): void
    {
        self::$handler = $handler;
        self::$accountId = $accountId;
    }

    public function onAny(array $update): void
    {
        if (self::$handler === null) {
            fwrite(STDERR, "TgUpdateForwarder not configured; dropping update.\n");
            return;
        }

        [$type, $data] = $this->normalize($update);
        if ($type === null) {
            return;
        }

        self::$handler->process(self::$accountId, $type, $data);
    }

    /**
     * @return array{0: ?string, 1: array} [type, data]; type null means "not a handled update".
     */
    private function normalize(array $update): array
    {
        $predicate = $update['_'] ?? '';

        if ($predicate === 'updateNewMessage' || $predicate === 'updateNewChannelMessage') {
            return ['updateNewMessage', $this->normalizeMessage($update['message'] ?? [])];
        }
        if ($predicate === 'updateEditMessage' || $predicate === 'updateEditChannelMessage') {
            return ['updateEditMessage', $this->normalizeMessage($update['message'] ?? [])];
        }
        if ($predicate === 'updateDeleteMessages' || $predicate === 'updateDeleteChannelMessages') {
            return ['updateDeleteMessages', $this->normalizeDelete($update)];
        }

        return [null, []];
    }

    private function normalizeMessage(array $msg): array
    {
        $msg['peer_id'] = self::peerId($msg['peer_id'] ?? []);
        return $msg;
    }

    private function normalizeDelete(array $update): array
    {
        $peer = $update['peer_id'] ?? ($update['channel_id'] ?? []);
        return [
            'peer_id' => self::peerId($peer),
            'ids' => array_map('intval', $update['messages'] ?? $update['ids'] ?? []),
        ];
    }

    private static function peerId(array $peer): int
    {
        return (int) match ($peer['_'] ?? '') {
            'peerUser' => $peer['user_id'] ?? 0,
            'peerChat' => $peer['chat_id'] ?? 0,
            'peerChannel' => $peer['channel_id'] ?? 0,
            default => $peer['user_id'] ?? $peer['chat_id'] ?? $peer['channel_id'] ?? 0,
        };
    }
}
