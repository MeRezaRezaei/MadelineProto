<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    The MadelineProto Team
 * @copyright 2016-2025 The MadelineProto Team
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Backup;

use Throwable;

final class Verifier
{
    private VaultInterface $vault;
    private BackupStore $store;

    public function __construct(VaultInterface $vault, BackupStore $store)
    {
        $this->vault = $vault;
        $this->store = $store;
    }

    public function verifyChunk(string $hash): bool
    {
        $row = $this->store->findChunk($hash);
        if ($row === null) {
            return false;
        }

        $channelId = $this->store->getChannel((string) $row['set_id']);
        if ($channelId === null) {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'verify-chunk-');
        try {
            $this->vault->downloadChunk($channelId, (int) $row['msg_id'], $tmp);
            $actualHash = Crypto::sha256File($tmp);
            return hash_equals($hash, $actualHash);
        } catch (Throwable) {
            return false;
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * @return array{checked: int, failed: list<string>}
     */
    public function verifyRandom(int $count): array
    {
        $hashes = $this->store->randomChunkHashes($count);
        $checked = 0;
        $failed = [];

        foreach ($hashes as $hash) {
            $checked++;
            if (!$this->verifyChunk($hash)) {
                $failed[] = $hash;
            }
        }

        return [
            'checked' => $checked,
            'failed' => $failed,
        ];
    }
}
