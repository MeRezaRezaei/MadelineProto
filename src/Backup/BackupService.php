<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\RelationalStore;
use RuntimeException;

final class BackupService
{
    private const MAX_PART_BYTES = 1500000000; // 1.5 GB

    public function __construct(
        private RelationalStore $store,
        private TelegramGateway $gw,
    ) {
    }

    /**
     * @return list<array{offset:int, length:int}>
     */
    public function splitPlan(int $size, int $maxBytes = self::MAX_PART_BYTES): array
    {
        if ($size <= 0) {
            return [];
        }
        $parts = [];
        for ($off = 0; $off < $size; $off += $maxBytes) {
            $parts[] = ['offset' => $off, 'length' => min($maxBytes, $size - $off)];
        }
        return $parts;
    }

    /** Upload an archive to a bucket; returns the completed job id. */
    public function backup(string $bucketName, string $archivePath): int
    {
        $row = $this->store->getBackupBucket($bucketName);
        if ($row === null) {
            throw new RuntimeException("Unknown backup bucket: {$bucketName}");
        }
        $bucket = BackupBucket::fromRow($row);

        if (!is_file($archivePath)) {
            throw new RuntimeException("Archive not found: {$archivePath}");
        }
        $size = filesize($archivePath);
        $plan = $this->splitPlan($size);
        if ($plan === []) {
            throw new RuntimeException('Empty archive');
        }

        $jobId = $this->store->insertBackupJob([
            'bucket_id' => $bucket->id,
            'status' => 'pending',
            'archive_name' => basename($archivePath),
            'size' => $size,
            'sha256' => null,
            'part_count' => count($plan),
            'message_ids' => null,
            'last_checked_message_id' => null,
            'completed_at' => null,
            'error' => null,
        ]);
        $this->store->updateBackupJob($jobId, ['status' => 'uploading']);

        $messageIds = [];
        $tmp = sys_get_temp_dir() . '/madeline_bk_' . $jobId . '_';
        try {
            $fh = fopen($archivePath, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Cannot open archive');
            }
            foreach ($plan as $i => $seg) {
                $partPath = $tmp . $i;
                $out = fopen($partPath, 'wb');
                fseek($fh, $seg['offset']);
                $remaining = $seg['length'];
                while ($remaining > 0) {
                    $buf = fread($fh, min(8192, $remaining));
                    if ($buf === false || $buf === '') {
                        break;
                    }
                    fwrite($out, $buf);
                    $remaining -= strlen($buf);
                }
                fclose($out);
                $messageIds[] = $this->gw->sendDocument($bucket->channelId, $partPath, $i + 1, count($plan));
                unlink($partPath);
            }
            fclose($fh);
        } catch (\Throwable $e) {
            $this->store->updateBackupJob($jobId, ['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }

        // Transactional: only mark completed AFTER every part confirmed.
        $this->store->updateBackupJob($jobId, [
            'status' => 'completed',
            'message_ids' => json_encode($messageIds),
            'completed_at' => date('c'),
        ]);
        return $jobId;
    }
}
