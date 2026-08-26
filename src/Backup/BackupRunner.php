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
use SplFileInfo;

final class BackupRunner
{
    private VaultInterface $vault;
    private BackupStore $store;

    public function __construct(VaultInterface $vault, BackupStore $store)
    {
        $this->vault = $vault;
        $this->store = $store;
    }

    /**
     * @param ?callable(string): void $log progress callback
     * @return string snapshot id (32-hex)
     */
    public function run(
        string $setId,
        BackupSetConfig $set,
        string $passphrase,
        int $defaultChunkSize = 1992294400,
        ?callable $log = null
    ): string {
        $channelId = $this->store->getChannel($setId);
        $saltHex = $this->store->getSalt($setId);

        if ($channelId === null) {
            $channelId = $this->vault->ensureChannel($setId);
        }
        if ($saltHex === null) {
            $saltHex = Crypto::generateSalt();
        }
        $this->store->setChannel($setId, $channelId, $saltHex);

        if ($set->preCommand() !== null) {
            $output = [];
            $exitCode = 0;
            exec($set->preCommand(), $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('pre_command failed: ' . $set->preCommand());
            }
        }

        $key = Crypto::deriveKey($passphrase, $saltHex);

        $collected = [];
        foreach ($set->paths() as $root) {
            if (is_file($root)) {
                $rel = basename($root);
                if ($set->matchesExclude($rel)) {
                    $collected[realpath($root)] = [
                        'abs' => realpath($root),
                        'rel' => $rel,
                    ];
                }
            } elseif (is_dir($root)) {
                $realRoot = realpath($root);
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );
                /** @var SplFileInfo $fileInfo */
                foreach ($it as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }
                    $abs = $fileInfo->getRealPath();
                    $rel = ltrim(substr($abs, strlen($realRoot)), '/\\');
                    if ($set->matchesExclude($rel)) {
                        $collected[$abs] = [
                            'abs' => $abs,
                            'rel' => $rel,
                        ];
                    }
                }
            }
        }

        $latest = $this->store->latestSnapshot($setId);
        $prevFiles = [];
        if ($latest !== null) {
            foreach ($this->store->snapshotFiles($latest['snapshot_id']) as $sf) {
                $prevFiles[$sf['path']] = $sf;
            }
        }

        $chunkSize = $set->chunkSize() ?? $defaultChunkSize;
        $tmpDir = sys_get_temp_dir() . '/madeline-backup-' . uniqid();
        mkdir($tmpDir, 0770, true);

        try {
            $filesRows = [];
            $manifestFiles = [];
            $totalBytes = 0;

            foreach ($collected as $item) {
                $abs = $item['abs'];
                $rel = $item['rel'];
                clearstatcache(true, $abs);
                $stat = stat($abs);
                $size = (int) $stat['size'];
                $mtime = (int) $stat['mtime'];
                $totalBytes += $size;

                if (
                    isset($prevFiles[$rel])
                    && (int) $prevFiles[$rel]['size'] === $size
                    && (int) $prevFiles[$rel]['mtime'] === $mtime
                ) {
                    $prevChunks = json_decode($prevFiles[$rel]['chunks_json'], true);
                    $allExist = true;
                    $reusedManifestChunks = [];
                    if (is_array($prevChunks)) {
                        foreach ($prevChunks as $h) {
                            $c = $this->store->findChunk($h);
                            if ($c === null) {
                                $allExist = false;
                                break;
                            }
                            $reusedManifestChunks[] = [
                                'hash' => $h,
                                'msg_id' => $c['msg_id'],
                                'file_id' => $c['file_id'],
                                'size' => $c['size'],
                            ];
                        }
                    } else {
                        $allExist = false;
                    }

                    if ($allExist) {
                        if ($log) {
                            $log("Unchanged: {$rel}");
                        }
                        $filesRows[] = [
                            'path' => $rel,
                            'size' => $size,
                            'mtime' => $mtime,
                            'sha256' => $prevFiles[$rel]['sha256'],
                            'chunks_json' => $prevFiles[$rel]['chunks_json'],
                        ];
                        $manifestFiles[] = [
                            'path' => $rel,
                            'size' => $size,
                            'mtime' => $mtime,
                            'sha256' => $prevFiles[$rel]['sha256'],
                            'chunks' => $reusedManifestChunks,
                        ];
                        continue;
                    }
                }

                if ($log) {
                    $log("Backing up: {$rel} ({$size} bytes)");
                }

                $fileSha256 = Crypto::sha256File($abs);
                $chunks = Chunker::split($abs, $chunkSize, $tmpDir);
                $chunkHashes = [];
                $manifestChunks = [];

                foreach ($chunks as $idx => $chunk) {
                    $encTmp = $tmpDir . '/enc-' . $idx . '-' . uniqid() . '.bin';
                    $cipherHash = Crypto::encryptFile($key, $chunk['tmpPath'], $encTmp);
                    if (file_exists($chunk['tmpPath'])) {
                        unlink($chunk['tmpPath']);
                    }

                    $existing = $this->store->findChunk($cipherHash);
                    if ($existing !== null) {
                        $msgId = $existing['msg_id'];
                        $fileId = $existing['file_id'];
                        $encSize = $existing['size'];
                        if (file_exists($encTmp)) {
                            unlink($encTmp);
                        }
                    } else {
                        $encSize = (int) filesize($encTmp);
                        [$msgId, $fileId] = $this->vault->uploadChunk($channelId, $cipherHash, $encTmp);
                        if (file_exists($encTmp)) {
                            unlink($encTmp);
                        }
                        $this->store->recordChunk($cipherHash, $setId, $msgId, $fileId, $encSize);
                    }

                    $chunkHashes[] = $cipherHash;
                    $manifestChunks[] = [
                        'hash' => $cipherHash,
                        'msg_id' => $msgId,
                        'file_id' => $fileId,
                        'size' => $encSize,
                    ];
                }

                $chunksJson = json_encode($chunkHashes, JSON_THROW_ON_ERROR);
                $filesRows[] = [
                    'path' => $rel,
                    'size' => $size,
                    'mtime' => $mtime,
                    'sha256' => $fileSha256,
                    'chunks_json' => $chunksJson,
                ];
                $manifestFiles[] = [
                    'path' => $rel,
                    'size' => $size,
                    'mtime' => $mtime,
                    'sha256' => $fileSha256,
                    'chunks' => $manifestChunks,
                ];
            }

            $snapshotId = bin2hex(random_bytes(16));
            $manifest = [
                'snapshot_id' => $snapshotId,
                'set_id' => $setId,
                'channel_id' => $channelId,
                'salt_hex' => $saltHex,
                'created_at' => date('c'),
                'files' => $manifestFiles,
            ];

            $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);
            $manifestMsgId = $this->vault->uploadManifest($channelId, $snapshotId, $manifestJson);
            $this->store->recordSnapshot($snapshotId, $setId, $manifestMsgId, $filesRows, $totalBytes);

            return $snapshotId;
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
