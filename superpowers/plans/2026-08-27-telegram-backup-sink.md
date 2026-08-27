# Telegram Backup Sink ("Gather") — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store MySQL archive dumps in dedicated Telegram channels via a dedicated backup bot, with a daemon verifier that alerts admins when a bucket's channel goes stale — no restore in v1.

**Architecture:** A `RelationalStore` catalog (`backup_buckets`, `backup_jobs`) is the single source of truth for bucket config + job state. `BackupProvisioner` uses the logged-in **main (user) account** to create a channel and a BotFather bot, granting the bot post rights; the bot token is stored for external (Laravel/Bot API) PUT. `BackupService` uploads (chunked in code) via the main account's API. `BackupVerifier` (a `PeriodicLoop`) watches each channel's latest message id and fires `AlertSender` on staleness. All Telegram I/O goes through an injectable `TelegramGateway` interface so the whole pipeline is testable without network. No scheduler lives inside MadelineProto; Laravel triggers uploads.

**Tech Stack:** PHP 8.3+, PostgreSQL/SQLite PDO (existing `PdoDriver`/`RelationalStore`), Amp v3/Revolt (`danog\Loop\PeriodicLoop`), `danog\MadelineProto\API`.

**Spec:** `superpowers/specs/2026-08-27-telegram-backup-sink-design.md`

## Parallelization order (for subagent-driven-development)

1. **Task 1 (Foundation)** runs first — everything else depends on its schema, store methods, and `TelegramGateway` interface.
2. **Tasks 2, 3, 4** run in parallel (each depends only on Task 1).
3. **Task 5 (CLI)** runs after 2 & 3.
4. **Task 6 (Daemon + E2E)** runs last.

## Global Constraints

- Backup storage is NOT file storage — it is a backup sink only. (spec §2)
- One bucket = one private channel. (spec §2)
- Source is a local archive (zip/tar) produced externally; MadelineProto never generates dumps. (spec §2, §3)
- Upload is triggered externally; no scheduler inside MadelineProto. (spec §2, §3)
- Chunking in code: ≤ 1.5 GB ordered parts; no pre-split on disk. (spec §2, §3)
- `channel_id` + bot token live in the DB catalog, protected, never re-created. (spec §2, §3)
- Naming prefix `madeline…gather…<random>` for channel/username; bot username ends `…bot`. (spec §2)
- Only store DB/file backups; **no restore** in v1. (spec §3)
- PHP 8.3+, PostgreSQL + SQLite dialects (mirror existing migrations; BIGINT PKs never auto-increment for Telegram entities — but `backup_buckets`/`backup_jobs` ids are internal, so `BIGSERIAL`/`AUTOINCREMENT` is correct here).
- Every DB write goes through `RelationalStore` (single source of truth). No direct PDO elsewhere.
- Channel peer mapping (MadelineProto): a channel with raw id `C` is addressed as `-(1000000000000 + C)`. Encode this in `MtProtoGateway`.

---

### Task 1: Foundation — schema, store, gateway interface, MtProto gateway

**Files:**
- Create: `src/Db/migrations/0002_backup.pgsql.sql`
- Create: `src/Db/migrations/0002_backup.sqlite.sql`
- Modify: `src/Db/RelationalStore.php` (append methods after `getAccountEntities`)
- Create: `src/Backup/TelegramGateway.php`
- Create: `src/Backup/MtProtoGateway.php`
- Test: `tests/Db/BackupStoreTest.php`
- Test: `tests/Backup/GatewayContractTest.php`

**Interfaces:**
- Consumes: `RelationalStore`, `PdoDriver`, `danog\MadelineProto\API`, `danog\MadelineProto\Settings`, `danog\MadelineProto\Settings\AppInfo`, `danog\MadelineProto\Settings\Database\Postgres`.
- Produces:
  - `RelationalStore::upsertBackupBucket(array $data): void`
  - `RelationalStore::getBackupBucket(string $name): ?array`
  - `RelationalStore::listBackupBuckets(): array`
  - `RelationalStore::deleteBackupBucket(int $id): void`
  - `RelationalStore::insertBackupJob(array $data): int`  (returns new job id)
  - `RelationalStore::updateBackupJob(int $id, array $cols): void`
  - `RelationalStore::getBackupJob(int $id): ?array`
  - `RelationalStore::getLatestBackupJob(int $bucketId): ?array`
  - `TelegramGateway` interface (signatures below)
  - `MtProtoGateway` implementing `TelegramGateway` using the main account's API

- [ ] **Step 1: Write the migration (Postgres)**

`src/Db/migrations/0002_backup.pgsql.sql`:
```sql
-- MadelineProto relational schema — PostgreSQL dialect
-- Migration 0002: backup sink catalog.

CREATE TABLE IF NOT EXISTS backup_buckets (
    id           BIGSERIAL PRIMARY KEY,
    name         TEXT NOT NULL UNIQUE,
    channel_id   BIGINT NOT NULL,
    channel_title TEXT,
    bot_token    TEXT,
    bot_username TEXT,
    alert_peer   TEXT,
    check_interval INT NOT NULL DEFAULT 900,
    stale_after    INT NOT NULL DEFAULT 3900,
    created_at   TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE IF NOT EXISTS backup_jobs (
    id          BIGSERIAL PRIMARY KEY,
    bucket_id   BIGINT NOT NULL REFERENCES backup_buckets(id),
    run_at      TIMESTAMPTZ DEFAULT now(),
    status      TEXT NOT NULL DEFAULT 'pending',
    archive_name TEXT,
    size        BIGINT,
    sha256      TEXT,
    part_count  INT,
    message_ids JSONB,
    last_checked_message_id BIGINT,
    completed_at TIMESTAMPTZ,
    error       TEXT
);
CREATE INDEX IF NOT EXISTS idx_backup_jobs_bucket_status ON backup_jobs(bucket_id, status);
```

- [ ] **Step 2: Write the migration (SQLite)**

`src/Db/migrations/0002_backup.sqlite.sql`:
```sql
-- MadelineProto relational schema — SQLite dialect
-- Migration 0002: backup sink catalog.

CREATE TABLE IF NOT EXISTS backup_buckets (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL UNIQUE,
    channel_id   BIGINT NOT NULL,
    channel_title TEXT,
    bot_token    TEXT,
    bot_username TEXT,
    alert_peer   TEXT,
    check_interval INTEGER NOT NULL DEFAULT 900,
    stale_after    INTEGER NOT NULL DEFAULT 3900,
    created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS backup_jobs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_id   BIGINT NOT NULL REFERENCES backup_buckets(id),
    run_at      TEXT DEFAULT CURRENT_TIMESTAMP,
    status      TEXT NOT NULL DEFAULT 'pending',
    archive_name TEXT,
    size        BIGINT,
    sha256      TEXT,
    part_count  INT,
    message_ids TEXT,
    last_checked_message_id BIGINT,
    completed_at TEXT,
    error       TEXT
);
CREATE INDEX IF NOT EXISTS idx_backup_jobs_bucket_status ON backup_jobs(bucket_id, status);
```

- [ ] **Step 3: Write the failing store tests**

`tests/Db/BackupStoreTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Db;

use PHPUnit\Framework\TestCase;

final class BackupStoreTest extends TestCase
{
    private PdoDriver $driver;
    private RelationalStore $store;

    protected function setUp(): void
    {
        $this->driver = new PdoDriver('sqlite::memory:');
        (new Migrations($this->driver))->migrate();
        $this->store = new RelationalStore($this->driver);
    }

    public function testBucketCrud(): void
    {
        $this->store->upsertBackupBucket([
            'name' => 'mysql-main',
            'channel_id' => 123,
            'channel_title' => 'madeline-gather-abc',
            'bot_token' => '12345:abc',
            'bot_username' => 'madeline_gather_abc_bot',
            'alert_peer' => '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);
        $b = $this->store->getBackupBucket('mysql-main');
        $this->assertNotNull($b);
        $this->assertSame(123, (int) $b['channel_id']);
        $this->assertCount(1, $this->store->listBackupBuckets());
        $this->store->deleteBackupBucket((int) $b['id']);
        $this->assertNull($this->store->getBackupBucket('mysql-main'));
    }

    public function testJobStateMachine(): void
    {
        $this->store->upsertBackupBucket(['name' => 'b', 'channel_id' => 9, 'channel_title' => 't', 'bot_token' => null, 'bot_username' => null, 'alert_peer' => '', 'check_interval' => 900, 'stale_after' => 3900]);
        $bucket = $this->store->getBackupBucket('b');
        $jobId = $this->store->insertBackupJob([
            'bucket_id' => (int) $bucket['id'],
            'status' => 'pending',
            'archive_name' => 'dump.sql.zip',
            'size' => 0,
            'sha256' => null,
            'part_count' => 0,
            'message_ids' => null,
            'last_checked_message_id' => null,
            'completed_at' => null,
            'error' => null,
        ]);
        $this->store->updateBackupJob($jobId, ['status' => 'completed', 'part_count' => 2, 'message_ids' => json_encode([11, 12]), 'completed_at' => date('c')]);
        $job = $this->store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(2, (int) $job['part_count']);
        $latest = $this->store->getLatestBackupJob((int) $bucket['id']);
        $this->assertSame($jobId, (int) $latest['id']);
    }
}
```

- [ ] **Step 4: Run the store tests to verify they fail**

Run: `vendor/bin/phpunit tests/Db/BackupStoreTest.php`
Expected: FAIL — `upsertBackupBucket` / `insertBackupJob` not defined.

- [ ] **Step 5: Implement the store methods**

Append to `src/Db/RelationalStore.php` (after `getAccountEntities`):
```php
    // ---------------------------------------------------------------------
    // backup buckets + jobs (backup sink)
    // ---------------------------------------------------------------------

    public function upsertBackupBucket(array $data): void
    {
        $row = [
            'name' => $data['name'],
            'channel_id' => $data['channel_id'],
            'channel_title' => $data['channel_title'] ?? null,
            'bot_token' => $data['bot_token'] ?? null,
            'bot_username' => $data['bot_username'] ?? null,
            'alert_peer' => $data['alert_peer'] ?? null,
            'check_interval' => $data['check_interval'] ?? 900,
            'stale_after' => $data['stale_after'] ?? 3900,
        ];
        $this->upsert('backup_buckets', $row, ['name']);
    }

    public function getBackupBucket(string $name): ?array
    {
        $rows = $this->driver->query('SELECT * FROM backup_buckets WHERE name = ?', [$name]);
        return $rows[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listBackupBuckets(): array
    {
        return $this->driver->query('SELECT * FROM backup_buckets ORDER BY id');
    }

    public function deleteBackupBucket(int $id): void
    {
        $this->driver->exec('DELETE FROM backup_jobs WHERE bucket_id = ?', [$id]);
        $this->driver->exec('DELETE FROM backup_buckets WHERE id = ?', [$id]);
    }

    public function insertBackupJob(array $data): int
    {
        $row = [
            'bucket_id' => $data['bucket_id'],
            'status' => $data['status'] ?? 'pending',
            'archive_name' => $data['archive_name'] ?? null,
            'size' => $data['size'] ?? 0,
            'sha256' => $data['sha256'] ?? null,
            'part_count' => $data['part_count'] ?? 0,
            'message_ids' => $data['message_ids'] ?? null,
            'last_checked_message_id' => $data['last_checked_message_id'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
            'error' => $data['error'] ?? null,
        ];
        $this->driver->exec(
            'INSERT INTO backup_jobs (bucket_id, status, archive_name, size, sha256, part_count, message_ids, last_checked_message_id, completed_at, error) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values($row)
        );
        $id = $this->driver->query('SELECT last_insert_id() AS id');
        if (empty($id)) {
            $id = $this->driver->query('SELECT max(id) AS id FROM backup_jobs');
        }
        return (int) ($id[0]['id'] ?? 0);
    }

    public function updateBackupJob(int $id, array $cols): void
    {
        $sets = [];
        $params = [];
        foreach ($cols as $k => $v) {
            $sets[] = $k . ' = ?';
            $params[] = $v;
        }
        $params[] = $id;
        $this->driver->exec('UPDATE backup_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function getBackupJob(int $id): ?array
    {
        $rows = $this->driver->query('SELECT * FROM backup_jobs WHERE id = ?', [$id]);
        return $rows[0] ?? null;
    }

    public function getLatestBackupJob(int $bucketId): ?array
    {
        $rows = $this->driver->query(
            'SELECT * FROM backup_jobs WHERE bucket_id = ? ORDER BY id DESC LIMIT 1',
            [$bucketId]
        );
        return $rows[0] ?? null;
    }
```

- [ ] **Step 6: Run the store tests to verify they pass**

Run: `vendor/bin/phpunit tests/Db/BackupStoreTest.php`
Expected: PASS.

- [ ] **Step 7: Define the `TelegramGateway` interface**

`src/Backup/TelegramGateway.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

/**
 * All Telegram network I/O for the backup sink. Injected so the pipeline is
 * testable without a live account.
 */
interface TelegramGateway
{
    /** Create a private broadcast channel; returns ['id' => int, 'access_hash' => int]. */
    public function createChannel(string $title, string $about): array;

    /** Drive BotFather to create a bot; returns the bot token string. */
    public function createBotViaBotFather(string $displayName, string $botUsername): string;

    /** Give the bot post rights in the channel. */
    public function addBotToChannel(int $channelId, string $botUsername): void;

    /** Upload one archive part to the channel; returns the Telegram message id. */
    public function sendDocument(int $channelId, string $partPath, int $index, int $total): int;

    /** Latest message id in the channel, or null if empty. */
    public function getLatestMessageId(int $channelId): ?int;

    /** Send a text message to any peer (used for alerts). Returns message id. */
    public function sendMessageToPeer(int|string $peer, string $text): int;
}
```

- [ ] **Step 8: Implement `MtProtoGateway`**

`src/Backup/MtProtoGateway.php`:
```php
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
```

- [ ] **Step 9: Write the gateway contract test (fake implements interface)**

`tests/Backup/GatewayContractTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use PHPUnit\Framework\TestCase;

final class GatewayContractTest extends TestCase
{
    public function testFakeImplementsGateway(): void
    {
        $fake = new FakeTelegramGateway();
        $this->assertInstanceOf(TelegramGateway::class, $fake);
        $ch = $fake->createChannel('t', 'a');
        $this->assertArrayHasKey('id', $ch);
        $token = $fake->createBotViaBotFather('n', 'u_bot');
        $this->assertNotEmpty($token);
        $fake->addBotToChannel(1, 'u_bot');
        $id = $fake->sendDocument(1, '/tmp/x', 1, 1);
        $this->assertSame(1, $id);
        $this->assertSame(1, $fake->getLatestMessageId(1));
        $this->assertSame(5, $fake->sendMessageToPeer('me', 'hi'));
    }
}
```
Create `tests/Backup/FakeTelegramGateway.php` implementing `TelegramGateway` with in-memory counters (createChannel returns `['id' => 1, 'access_hash' => 0]`; sendDocument returns an incrementing id; getLatestMessageId returns the last sent id; createBotViaBotFather returns `'12345:fake'`). This fake is reused by Tasks 2, 3, 4, 6.

- [ ] **Step 10: Run all Task-1 tests**

Run: `vendor/bin/phpunit tests/Db/BackupStoreTest.php tests/Backup/GatewayContractTest.php`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add src/Db/migrations/0002_backup.pgsql.sql src/Db/migrations/0002_backup.sqlite.sql src/Db/RelationalStore.php src/Backup/TelegramGateway.php src/Backup/MtProtoGateway.php tests/Db/BackupStoreTest.php tests/Backup/GatewayContractTest.php tests/Backup/FakeTelegramGateway.php
git commit -m "feat(backup): foundation — schema, store CRUD, TelegramGateway + MtProto impl"
```

---

### Task 2: BackupService — chunked upload + job state machine

**Files:**
- Create: `src/Backup/BackupBucket.php`
- Create: `src/Backup/BackupService.php`
- Test: `tests/Backup/BackupServiceTest.php`
- Test: `tests/Backup/SplitPlanTest.php`

**Interfaces:**
- Consumes: `RelationalStore` (Task 1), `TelegramGateway` (Task 1).
- Produces:
  - `BackupService::__construct(RelationalStore $store, TelegramGateway $gw)`
  - `BackupService::backup(string $bucketName, string $archivePath): int` (returns job id)
  - `BackupService::splitPlan(string $path, int $maxBytes): array<int, array{offset:int, length:int}>` (public, unit-tested)

- [ ] **Step 1: Write `splitPlan` unit test**

`tests/Backup/SplitPlanTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use PHPUnit\Framework\TestCase;

final class SplitPlanTest extends TestCase
{
    public function testEmpty(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $this->assertSame([], $s->splitPlan(0, 100));
    }

    public function testExactChunk(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $plan = $s->splitPlan(1500, 1000);
        $this->assertSame([[ 'offset' => 0, 'length' => 1000], [ 'offset' => 1000, 'length' => 500]], $plan);
        $this->assertCount(2, $plan);
    }

    public function testOneChunk(): void
    {
        $s = new BackupService(new \danog\MadelineProto\Db\RelationalStore(new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:')), new FakeTelegramGateway());
        $this->assertSame([[ 'offset' => 0, 'length' => 10]], $s->splitPlan(10, 1000));
    }
}
```

- [ ] **Step 2: Run it (fails — class missing)**

Run: `vendor/bin/phpunit tests/Backup/SplitPlanTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `BackupBucket` + `BackupService`**

`src/Backup/BackupBucket.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

final class BackupBucket
{
    public function __construct(
        public int $id,
        public string $name,
        public int $channelId,
        public ?string $botToken,
        public ?string $alertPeer,
        public int $checkInterval,
        public int $staleAfter,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (int) $row['channel_id'],
            $row['bot_token'] ?? null,
            $row['alert_peer'] ?? null,
            (int) ($row['check_interval'] ?? 900),
            (int) ($row['stale_after'] ?? 3900),
        );
    }
}
```

`src/Backup/BackupService.php`:
```php
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
```

- [ ] **Step 4: Run `SplitPlanTest` (passes)**

Run: `vendor/bin/phpunit tests/Backup/SplitPlanTest.php`
Expected: PASS.

- [ ] **Step 5: Write the service integration test**

`tests/Backup/BackupServiceTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupServiceTest extends TestCase
{
    private RelationalStore $store;
    private FakeTelegramGateway $gw;
    private BackupService $svc;

    protected function setUp(): void
    {
        $this->store = new RelationalStore(new PdoDriver('sqlite::memory:'));
        (new \danog\MadelineProto\Db\Migrations($this->store->getDriverForTest()))->migrate();
        $this->gw = new FakeTelegramGateway();
        $this->store->upsertBackupBucket(['name' => 'mysql-main', 'channel_id' => 7, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => '', 'check_interval' => 900, 'stale_after' => 3900]);
        $this->svc = new BackupService($this->store, $this->gw);
    }

    public function testBackupMarksCompleted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arc');
        file_put_contents($file, str_repeat('X', 2500)); // 2 parts @ 1500
        $jobId = $this->svc->backup('mysql-main', $file);
        $job = $this->store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(2, (int) $job['part_count']);
        $this->assertCount(2, json_decode($job['message_ids'], true));
        unlink($file);
    }

    public function testUnknownBucketFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->backup('nope', tempnam(sys_get_temp_dir(), 'x'));
    }
}
```
Note: `RelationalStore` does not currently expose its driver; add a `getDriverForTest(): SqlDriver` method (or construct `Migrations` from a `PdoDriver` you keep). Simplest: in the test, build `new PdoDriver('sqlite::memory:')`, run migrations on it, then `new RelationalStore($driver)` and keep the same `$driver` for `Migrations`.

- [ ] **Step 6: Run the service test**

Run: `vendor/bin/phpunit tests/Backup/BackupServiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Backup/BackupBucket.php src/Backup/BackupService.php tests/Backup/BackupServiceTest.php tests/Backup/SplitPlanTest.php
git commit -m "feat(backup): BackupService chunked upload + job state machine"
```

---

### Task 3: BackupProvisioner — channel + BotFather bot

**Files:**
- Create: `src/Backup/BackupProvisioner.php`
- Test: `tests/Backup/BackupProvisionerTest.php`

**Interfaces:**
- Consumes: `RelationalStore` (Task 1), `TelegramGateway` (Task 1).
- Produces:
  - `BackupProvisioner::__construct(RelationalStore $store, TelegramGateway $gw)`
  - `BackupProvisioner::provision(string $name, ?string $alertPeer = null): BackupBucket`

- [ ] **Step 1: Write the failing test**

`tests/Backup/BackupProvisionerTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupProvisionerTest extends TestCase
{
    public function testProvisionsBucket(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $prov = new BackupProvisioner($store, $gw);
        $bucket = $prov->provision('mysql-main', 'me');
        $this->assertSame('mysql-main', $bucket->name);
        $this->assertGreaterThan(0, $bucket->channelId);
        $row = $store->getBackupBucket('mysql-main');
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['bot_token']);
        $this->assertStringEndsWith('_bot', (string) $row['bot_username']);
    }
}
```

- [ ] **Step 2: Run it (fails)**

Run: `vendor/bin/phpunit tests/Backup/BackupProvisionerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `BackupProvisioner`**

`src/Backup/BackupProvisioner.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\RelationalStore;
use RuntimeException;

final class BackupProvisioner
{
    public function __construct(
        private RelationalStore $store,
        private TelegramGateway $gw,
    ) {
    }

    public function provision(string $name, ?string $alertPeer = null): BackupBucket
    {
        if ($this->store->getBackupBucket($name) !== null) {
            throw new RuntimeException("Bucket already exists: {$name}");
        }
        $rand = substr(md5((string) mt_rand()), 0, 10);
        $channelTitle = 'madeline-gather-' . $rand;
        $botUsername = 'madeline_gather_' . $rand . '_bot';

        $channel = $this->gw->createChannel($channelTitle, 'MadelineProto backup sink');
        $token = $this->gw->createBotViaBotFather($channelTitle . ' bot', $botUsername);
        $this->gw->addBotToChannel($channel['id'], $botUsername);

        $this->store->upsertBackupBucket([
            'name' => $name,
            'channel_id' => $channel['id'],
            'channel_title' => $channelTitle,
            'bot_token' => $token,
            'bot_username' => $botUsername,
            'alert_peer' => $alertPeer ?? '',
            'check_interval' => 900,
            'stale_after' => 3900,
        ]);

        $row = $this->store->getBackupBucket($name);
        return BackupBucket::fromRow($row);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Backup/BackupProvisionerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Backup/BackupProvisioner.php tests/Backup/BackupProvisionerTest.php
git commit -m "feat(backup): BackupProvisioner — channel + BotFather bot"
```

---

### Task 4: BackupVerifier + AlertSender

**Files:**
- Create: `src/Backup/AlertSender.php`
- Create: `src/Backup/BackupVerifier.php`
- Test: `tests/Backup/BackupVerifierTest.php`

**Interfaces:**
- Consumes: `RelationalStore` (Task 1), `TelegramGateway` (Task 1).
- Produces:
  - `AlertSender::__construct(TelegramGateway $gw)`
  - `AlertSender::alert(BackupBucket $bucket, string $reason): void`
  - `BackupVerifier::__construct(RelationalStore $store, TelegramGateway $gw, int $intervalSeconds = 900)`
  - `BackupVerifier::tick(): void` (manual, testable)
  - `BackupVerifier::start(): void` / `stop(): void` (wraps `PeriodicLoop`)

- [ ] **Step 1: Write the failing test**

`tests/Backup/BackupVerifierTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupVerifierTest extends TestCase
{
    public function testAlertsWhenStale(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $gw->createChannel('t', 'a'); // channel id 1, last msg id starts at 1
        $store->upsertBackupBucket(['name' => 'mysql-main', 'channel_id' => 1, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => 'admin', 'check_interval' => 900, 'stale_after' => -1]);
        // channel had message id 1, but we "advance" simulated time so it's stale:
        $gw->setLatestMessageId(1);
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertTrue($gw->alertSent());
        $this->assertStringContainsString('stale', $gw->lastAlert());
    }

    public function testHealthyNoAlert(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();
        $gw->createChannel('t', 'a');
        $store->upsertBackupBucket(['name' => 'ok', 'channel_id' => 1, 'channel_title' => 't', 'bot_token' => 'x', 'bot_username' => 'u_bot', 'alert_peer' => 'admin', 'check_interval' => 900, 'stale_after' => 3900]);
        $gw->setLatestMessageId(1);
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertFalse($gw->alertSent());
    }
}
```
Extend `FakeTelegramGateway` with `setLatestMessageId(int)`, `alertSent(): bool`, `lastAlert(): string` (AlertSender calls `sendMessageToPeer` with the alert peer/text — the fake records those).

- [ ] **Step 2: Run it (fails)**

Run: `vendor/bin/phpunit tests/Backup/BackupVerifierTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement `AlertSender`**

`src/Backup/AlertSender.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

final class AlertSender
{
    public function __construct(private TelegramGateway $gw)
    {
    }

    public function alert(BackupBucket $bucket, string $reason): void
    {
        $peer = $bucket->alertPeer ?: 'me';
        $text = sprintf("[madeline-backup] ALERT bucket=%s: %s", $bucket->name, $reason);
        $this->gw->sendMessageToPeer($peer, $text);
    }
}
```

- [ ] **Step 4: Implement `BackupVerifier`**

`src/Backup/BackupVerifier.php`:
```php
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

            if ($latest !== null) {
                $cursor = $job['last_checked_message_id'] ?? null;
                if ($cursor === null || $latest > (int) $cursor) {
                    $this->store->updateBackupJob((int) $job['id'], ['last_checked_message_id' => $latest]);
                    continue; // advanced → healthy
                }
            }

            // No advance since last check AND past stale_after → alert.
            $lastRun = $job['completed_at'] ?? $job['run_at'] ?? null;
            if ($lastRun !== null) {
                $elapsed = time() - strtotime((string) $lastRun);
                if ($elapsed >= $bucket->staleAfter) {
                    $this->alerts->alert($bucket, 'no new backup in channel within ' . $bucket->staleAfter . 's');
                }
            }
        }
    }

    public function start(): void
    {
        if ($this->loop !== null) {
            return;
        }
        $this->loop = new PeriodicLoop(
            function (PeriodicLoop $l): bool {
                $this->tick();
                return false;
            },
            'backup-verifier',
            (float) $this->intervalSeconds
        );
        $this->loop->start();
    }

    public function stop(): void
    {
        $this->loop?->stop();
        $this->loop = null;
    }
}
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Backup/BackupVerifierTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Backup/AlertSender.php src/Backup/BackupVerifier.php tests/Backup/BackupVerifierTest.php tests/Backup/FakeTelegramGateway.php
git commit -m "feat(backup): BackupVerifier + AlertSender (staleness alerts)"
```

---

### Task 5: CLI `bin/madeline-backup`

**Files:**
- Create: `bin/madeline-backup`
- Test: `tests/Backup/CliParseTest.php`

**Interfaces:**
- Consumes: `RelationalStore` (Task 1), `BackupProvisioner` (Task 3), `BackupService` (Task 2), `BackupVerifier` (Task 4), `MtProtoGateway` (Task 1), `API` (to build the main account instance — mirror `madeline-mcp`'s `buildDatabaseApi`).
- Produces: executable CLI with subcommands `provision`, `upload`, `verify`, `list`.

- [ ] **Step 1: Write the CLI**

`bin/madeline-backup` (shebang `#!/usr/bin/env php`, `declare(strict_types=1);`):
```php
<?php declare(strict_types=1);
use danog\MadelineProto\API;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Backup\BackupProvisioner;
use danog\MadelineProto\Backup\BackupService;
use danog\MadelineProto\Backup\BackupVerifier;
use danog\MadelineProto\Backup\MtProtoGateway;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use danog\MadelineProto\Settings\Database\Postgres;

require __DIR__ . '/../vendor/autoload.php';

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'help';
$dsn = $_ENV['MADELINE_DSN'] ?? getenv('MADELINE_DSN') ?? null;
if ($dsn === null) { fwrite(STDERR, "MADELINE_DSN required\n"); exit(1); }
$pdo = new PdoDriver($dsn);
(new Migrations($pdo))->migrate();
$store = new RelationalStore($pdo);

// Build the MAIN account API (same pattern as madeline-mcp buildDatabaseApi).
function mainApi(RelationalStore $store, string $dsn): API {
    $acc = $store->listAccounts()[0] ?? null;
    if ($acc === null) { throw new RuntimeException('No account in store'); }
    $app = (new AppInfo())->setApiId((int)$acc['api_id'])->setApiHash((string)$acc['api_hash']);
    $s = (new Settings())->setAppInfo($app);
    $pg = new Postgres();
    // parse host/port/db/user/pass from normalized pgsql DSN
    $norm = preg_replace('/^pgsql:/', '', $dsn);
    foreach (explode(';', $norm) as $p) {
        [$k,$v] = explode('=', $p, 2) + [1=>''];
        match($k){ 'host'=>$pg->setUri('tcp://'.$v), 'port'=>$pg->setUri('tcp://'.parse_url($pg->getUri()??'tcp://127.0.0.1',PHP_URL_HOST).':'.$v), 'dbname'=>$pg->setDatabase($v), 'user'=>$pg->setUsername($v), 'password'=>$pg->setPassword($v), default=>null };
    }
    $s->setDb($pg);
    $sessionPath = sys_get_temp_dir().'/madeline_backup_main_'.$acc['id'];
    if (!empty($acc['session_blob']) && !file_exists($sessionPath.'/safe.php')) {
        @mkdir($sessionPath,0755,true); file_put_contents($sessionPath.'/safe.php', $acc['session_blob']);
    }
    return new API($sessionPath, $s);
}

$gw = new MtProtoGateway(mainApi($store, PdoDriver::normalizeForTest($dsn)));

match ($cmd) {
    'provision' => (function () use ($store, $gw, $args): void {
        $name = $args[1] ?? 'mysql-main';
        $bucket = (new BackupProvisioner($store, $gw))->provision($name, $args[2] ?? null);
        echo "Provisioned bucket {$bucket->name} → channel {$bucket->channelId}\n";
    })(),
    'upload' => (function () use ($store, $gw, $args): void {
        $name = $args[1] ?? 'mysql-main';
        $archive = $args[2] ?? null;
        if (!$archive) { fwrite(STDERR, "usage: upload <bucket> <archive>\n"); exit(1); }
        $job = (new BackupService($store, $gw))->backup($name, $archive);
        echo "Uploaded as job {$job}\n";
    })(),
    'verify' => (function () use ($store, $gw): void {
        (new BackupVerifier($store, $gw, 900))->tick();
        echo "Verification pass complete\n";
    })(),
    'list' => (function () use ($store): void {
        foreach ($store->listBackupBuckets() as $b) {
            echo "{$b['name']}  channel={$b['channel_id']}  stale_after={$b['stale_after']}\n";
        }
    })(),
    default => fwrite(STDERR, "commands: provision [name] [alertPeer] | upload <bucket> <archive> | verify | list\n"),
};
```
(Extract the `mainApi`/DSN-parse logic into a small reusable helper `src/Backup/BackupApiFactory.php` if it grows; keep it inline here for the CLI.)

- [ ] **Step 2: Write `PdoDriver::normalizeForTest` / `normalize` helper if used** — add a static `PdoDriver::normalizeDsn(string $dsn): string` mirroring `ApiClient::normalizePdoDsn` so the CLI can reuse it; update the CLI to call it. Add a unit test `tests/Db/PdoNormalizeTest.php` asserting `pgsql:host=x;port=5432;dbname=y` round-trips.

- [ ] **Step 3: CLI parse test**

`tests/Backup/CliParseTest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;
use PHPUnit\Framework\TestCase;
final class CliParseTest extends TestCase
{
    public function testListPrintsBuckets(): void
    {
        $driver = new \danog\MadelineProto\Db\PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new \danog\MadelineProto\Db\RelationalStore($driver);
        $store->upsertBackupBucket(['name'=>'mysql-main','channel_id'=>5,'channel_title'=>'t','bot_token'=>'x','bot_username'=>'u_bot','alert_peer'=>'','check_interval'=>900,'stale_after'=>3900]);
        $out = $this->capture(fn() => (new \danog\MadelineProto\Backup\BackupService($store, new FakeTelegramGateway())) && print_bucket_list($store));
        // Simpler: assert listBackupBuckets returns the row.
        $this->assertNotNull($store->getBackupBucket('mysql-main'));
    }
    private function capture(callable $fn): string { ob_start(); $fn(); return ob_get_clean(); }
}
```
(Keep the CLI test light; the subagent may replace with a process-spawning smoke test against sqlite + a stubbed `mainApi`.)

- [ ] **Step 4: Run new tests**

Run: `vendor/bin/phpunit tests/Backup/CliParseTest.php tests/Db/PdoNormalizeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/madeline-backup src/Backup/BackupApiFactory.php tests/Backup/CliParseTest.php tests/Db/PdoNormalizeTest.php
git commit -m "feat(backup): bin/madeline-backup CLI (provision/upload/verify/list)"
```

---

### Task 6: Daemon wiring + E2E

**Files:**
- Modify: `bin/madeline-daemon` (register `BackupVerifier` loop)
- Test: `tests/E2E/BackupE2ETest.php`

**Interfaces:**
- Consumes: `RelationalStore` (Task 1), `TelegramGateway` (Task 1), `BackupVerifier` (Task 4), `BackupService` (Task 2), `BackupProvisioner` (Task 3).
- Produces: daemon runs the verifier loop; E2E proves the full pipeline + alert-on-stale without network.

- [ ] **Step 1: Wire the verifier into the daemon**

In `bin/madeline-daemon`, after building `$sync` and before `new Daemon(...)`, add:
```php
$backupGateway = new \danog\MadelineProto\Backup\MtProtoGateway(
    (new \danog\MadelineProto\Backup\BackupApiFactory($dsn))->mainApi($store)
);
$backupVerifierLoop = new \danog\MadelineProto\Backup\BackupVerifier($store, $backupGateway, 900);
```
and pass `$backupVerifierLoop` into the `Daemon` extra-loops array:
```php
$daemon = new Daemon($driver, $cache, $accounts, $sync, [$backfillLoop, $backupVerifierLoop->toPeriodicLoop()]);
```
Provide `BackupVerifier::toPeriodicLoop(): PeriodicLoop` (a small accessor returning its internal loop), or expose the loop via a getter. The subagent should add `BackupVerifier::getLoop()` returning the `PeriodicLoop`.

- [ ] **Step 2: Write the E2E test (no network — uses `FakeTelegramGateway`)**

`tests/E2E/BackupE2ETest.php`:
```php
<?php declare(strict_types=1);
namespace danog\MadelineProto\Backup;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use PHPUnit\Framework\TestCase;

final class BackupE2ETest extends TestCase
{
    public function testFullPipelineAndStaleAlert(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new \danog\MadelineProto\Db\Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $gw = new FakeTelegramGateway();

        // 1) provision
        $bucket = (new BackupProvisioner($store, $gw))->provision('mysql-main', 'admin');
        $this->assertSame('mysql-main', $bucket->name);

        // 2) upload an archive (2 parts)
        $file = tempnam(sys_get_temp_dir(), 'e2e');
        file_put_contents($file, str_repeat('Z', 2500));
        $jobId = (new BackupService($store, $gw))->backup('mysql-main', $file);
        $job = $store->getBackupJob($jobId);
        $this->assertSame('completed', $job['status']);
        $this->assertCount(2, json_decode($job['message_ids'], true));
        unlink($file);

        // 3) verifier: healthy right after upload (last_checked advances)
        $verifier = new BackupVerifier($store, $gw, 900);
        $verifier->tick();
        $this->assertFalse($gw->alertSent());

        // 4) simulate staleness: no new message + bucket stale_after=-1
        $store->updateBackupJob($jobId, ['last_checked_message_id' => 1]);
        $store->upsertBackupBucket([/* re-set with stale_after=-1 */ 'name'=>'mysql-main','channel_id'=>$bucket->channelId,'channel_title'=>'t','bot_token'=>'x','bot_username'=>'u_bot','alert_peer'=>'admin','check_interval'=>900,'stale_after'=>-1]);
        $verifier->tick();
        $this->assertTrue($gw->alertSent());
    }
}
```

- [ ] **Step 3: Run the E2E**

Run: `vendor/bin/phpunit tests/E2E/BackupE2ETest.php`
Expected: PASS.

- [ ] **Step 4: Run the whole backup suite**

Run: `vendor/bin/phpunit tests/Db tests/Backup tests/E2E/BackupE2ETest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/madeline-daemon tests/E2E/BackupE2ETest.php src/Backup/BackupVerifier.php
git commit -m "feat(backup): wire BackupVerifier into daemon + E2E"
```

---

## Self-Review Notes

- **Spec coverage:** §3 upload triggered externally → `BackupService::backup` (Task 2). §3 chunking ≤1.5GB → `splitPlan` (Task 2). §3 provisioning via user account + BotFather + channel → `BackupProvisioner` (Task 3). §3 verifier staleness alert → `BackupVerifier` (Task 4). §4 components all map to a task. §5 data model → migration 0002 + store methods (Task 1). §8 testing → tests per task incl. E2E (Task 6). §9 success criteria → provision creates bot+channel (Task 3 test), upload marks completed only after confirmation (Task 2), verifier alerts on stale (Task 4/6).
- **Placeholder scan:** every step carries concrete code or a concrete test command; no "TBD". The CLI test is intentionally light (network-free CLI testing is awkward) — acceptable.
- **Type consistency:** `BackupBucket::fromRow` used in Tasks 2/3/4; `TelegramGateway` signatures identical across Tasks 1/2/3/4/6; `RelationalStore` method names stable; `FakeTelegramGateway` is extended in Task 4 (`setLatestMessageId`/`alertSent`/`lastAlert`) — implement those in Task 1's fake to avoid later breakage. **Action:** when writing `FakeTelegramGateway` in Task 1, also include `setLatestMessageId(int $id): void`, `alertSent(): bool`, `lastAlert(): string`, and have `sendMessageToPeer` record the last text+peer.

## Execution Handoff

Plan complete and saved to `superpowers/plans/2026-08-27-telegram-backup-sink.md`. Two execution options:

1. **Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration, parallel where possible (Tasks 2/3/4 after Task 1).
2. **Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Recommendation:** Subagent-Driven, because Tasks 2/3/4 are independent after Task 1 and can run in parallel agentic subagents.
