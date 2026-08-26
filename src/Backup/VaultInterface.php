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

interface VaultInterface
{
    /** Ensure the private channel for a backup set exists; returns channel id. */
    public function ensureChannel(string $setId): int;

    /**
     * Upload chunk file $tmpPath named $name (ciphertext hash).
     * @return array{0: int, 1: string} [msg_id, file_id]
     */
    public function uploadChunk(int $channelId, string $name, string $tmpPath): array;

    /** Upload manifest JSON; returns msg_id. */
    public function uploadManifest(int $channelId, string $snapshotId, string $json): int;

    /** Download chunk with $msgId from channel into $destPath. */
    public function downloadChunk(int $channelId, int $msgId, string $destPath): void;

    /** Download + return manifest JSON string by msg_id. */
    public function downloadManifest(int $channelId, int $msgId): string;
}
