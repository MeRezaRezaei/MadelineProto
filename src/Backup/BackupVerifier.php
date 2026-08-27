<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\Loop\PeriodicLoop;
use danog\MadelineProto\Db\RelationalStore;

final class BackupVerifier
{
    private ?PeriodicLoop $loop = null;
    private AlertSender $alerts;

    public function __construct(
        private RelationalStore $store,
        private TelegramGateway $gw,
        private int $intervalSeconds = 900,
        private int $uploadTimeoutSeconds = 1800,
    ) {
        $this->alerts = new AlertSender($gw);
    }

    /** One verification pass over all buckets. Callable from tests. */
    public function tick(): void
    {
        foreach ($this->store->listBackupBuckets() as $row) {
            $bucket = BackupBucket::fromRow($row);
            $latest = $this->gw->getLatestMessageId($bucket->channelId);
            $job = $this->store->getLatestBackupJob($bucket->id);

            if ($job !== null && $latest !== null) {
                $cursor = $job['last_checked_message_id'] ?? null;
                if ($cursor === null || $latest > (int) $cursor) {
                    $this->store->updateBackupJob((int) $job['id'], ['last_checked_message_id' => $latest]);
                    // advanced → healthy for staleness; still check stuck uploads below
                }
            }

            // No advance since last check (or no job yet) → evaluate staleness.
            if ($job !== null) {
                $lastRun = $job['completed_at'] ?? $job['run_at'] ?? null;
                $elapsed = $lastRun !== null ? time() - strtotime((string) $lastRun) : 0;
            } else {
                // No backup recorded yet: treat as a freshly created baseline.
                $elapsed = 0;
            }

            if ($elapsed >= $bucket->staleAfter) {
                $this->alerts->alert($bucket, 'stale: no new backup in channel within ' . $bucket->staleAfter . 's');
            }

            // Spec §6.4: alert on jobs wedged in `uploading` (upload never finished).
            foreach ($this->store->getStuckUploadingJobs($bucket->id, $this->uploadTimeoutSeconds) as $stuck) {
                $this->alerts->alert(
                    $bucket,
                    'stuck: backup job ' . $stuck['id'] . ' has been uploading for > ' . $this->uploadTimeoutSeconds . 's'
                );
            }
        }
    }

    /** Lazily build (and return) the internal periodic loop. */
    public function getLoop(): \danog\Loop\PeriodicLoop
    {
        if ($this->loop === null) {
            $this->loop = new PeriodicLoop(
                function (PeriodicLoop $l): bool {
                    $this->tick();
                    return false;
                },
                'backup-verifier',
                (float) $this->intervalSeconds
            );
        }
        return $this->loop;
    }

    public function start(): void
    {
        $this->getLoop()->start();
    }

    public function stop(): void
    {
        $this->loop?->stop();
        $this->loop = null;
    }
}
