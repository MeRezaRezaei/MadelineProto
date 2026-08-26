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

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Restorer
{
    private VaultInterface $vault;
    private BackupStore $store;

    public function __construct(VaultInterface $vault, BackupStore $store)
    {
        $this->vault = $vault;
        $this->store = $store;
    }

    /**
     * @param ?callable(string): void $log
     * @return int number of files restored
     */
    public function restore(
        string $setId,
        string $toDir,
        string $passphrase,
        ?string $snapshotId = null,
        ?callable $log = null
    ): int {
        $channelId = $this->store->getChannel($setId);
        if ($channelId === null) {
            throw new RuntimeException("No channel found for backup set {$setId}");
        }

        if ($snapshotId !== null) {
            $snapshots = $this->store->listSnapshots($setId);
            $found = null;
            foreach ($snapshots as $snap) {
                if ($snap['snapshot_id'] === $snapshotId) {
                    $found = $snap;
                    break;
                }
            }
            if ($found === null) {
                throw new RuntimeException("Snapshot {$snapshotId} not found for set {$setId}");
            }
            $manifestMsgId = (int) $found['manifest_msg_id'];
        } else {
            $latest = $this->store->latestSnapshot($setId);
            if ($latest === null) {
                throw new RuntimeException("No snapshots found for set {$setId}");
            }
            $manifestMsgId = (int) $latest['manifest_msg_id'];
        }

        $json = $this->vault->downloadManifest($channelId, $manifestMsgId);
        return $this->restoreFromManifestJson($json, $toDir, $passphrase, $log);
    }

    /**
     * Manifest-only recovery: no index needed.
     * @param ?callable(string): void $log
     * @return int number of files restored
     */
    public function restoreFromManifestJson(
        string $json,
        string $toDir,
        string $passphrase,
        ?callable $log = null
    ): int {
        $manifest = json_decode($json, true);
        if (!is_array($manifest) || !isset($manifest['salt_hex'], $manifest['channel_id'], $manifest['files'])) {
            throw new RuntimeException('Invalid manifest JSON');
        }

        $key = Crypto::deriveKey($passphrase, (string) $manifest['salt_hex']);
        $channelId = (int) $manifest['channel_id'];

        $tmpDir = sys_get_temp_dir() . '/madeline-restore-' . uniqid();
        mkdir($tmpDir, 0770, true);

        $restoredCount = 0;
        try {
            foreach ($manifest['files'] as $file) {
                $relPath = ltrim((string) $file['path'], '/\\');
                $dest = rtrim($toDir, '/\\') . '/' . $relPath;
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0777, true);
                }

                if (file_exists($dest)) {
                    unlink($dest);
                }

                $outHandle = fopen($dest, 'ab');
                if ($outHandle === false) {
                    throw new RuntimeException("Failed to open destination file for writing: {$dest}");
                }

                try {
                    foreach ($file['chunks'] as $chunk) {
                        $tmpEnc = $tmpDir . '/chunk-enc-' . uniqid() . '.bin';
                        $tmpDec = $tmpDir . '/chunk-dec-' . uniqid() . '.bin';

                        $this->vault->downloadChunk($channelId, (int) $chunk['msg_id'], $tmpEnc);
                        Crypto::decryptFile($key, $tmpEnc, $tmpDec);
                        if (file_exists($tmpEnc)) {
                            unlink($tmpEnc);
                        }

                        $decHandle = fopen($tmpDec, 'rb');
                        if ($decHandle === false) {
                            throw new RuntimeException("Failed to open decrypted chunk temp file: {$tmpDec}");
                        }
                        stream_copy_to_stream($decHandle, $outHandle);
                        fclose($decHandle);

                        if (file_exists($tmpDec)) {
                            unlink($tmpDec);
                        }
                    }
                } finally {
                    fclose($outHandle);
                }

                $actualSha256 = Crypto::sha256File($dest);
                if ($actualSha256 !== $file['sha256']) {
                    unlink($dest);
                    throw new RuntimeException("Integrity failure: {$file['path']}");
                }

                if (isset($file['mtime'])) {
                    touch($dest, (int) $file['mtime']);
                }

                if ($log) {
                    $log("Restored: {$file['path']}");
                }
                $restoredCount++;
            }

            return $restoredCount;
        } finally {
            if (is_dir($tmpDir)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($tmpDir);
            }
        }
    }
}
