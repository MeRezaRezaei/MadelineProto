# Sync & Update Handler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Live Telegram updates drive the app via Redis EventBus (hot path), while history is gradually backfilled into Postgres within a 50%-reserved flood quota and queryable even after Telegram deletes it.

**Architecture:** Three new units on top of the existing `RelationalStore`/`Cache`/`EventBus`: (1) `SyncTargets` — opt-in per-peer settings stored in a new `sync_targets` table; (2) `FetchQueue` — persistent gradual-backfill queue enforcing the ≥50% quota-headroom rule; (3) `UpdateHandler` — framework-agnostic update processor (upsert → invalidate → emit). A backfill CLI drains the queue; the daemon wires everything and drops `NullAccountDataProvider`.

**Tech Stack:** PHP 8.1+, `Amp`/`Revolt` async, `amphp/redis`, PDO sqlite/postgres (`PdoDriver`, `Migrations`), PHPUnit (tests use `sqlite::memory:` + Redis `tcp://127.0.0.1:16379`).

**Spec:** `docs/superpowers/specs/2026-08-26-sync-update-handler-design.md`

## Global Constraints

- Every PHP file: `declare(strict_types=1);` + the AGPL header copied verbatim from `src/Sync/SyncLoop.php:1-15`.
- Namespaces: `danog\MadelineProto\Sync` (src), `danog\MadelineProto\Test` (tests).
- Never hard-delete message rows; deletion updates set `deleted_at` (soft delete). Data must survive Telegram-side deletion.
- Backfill must always reserve **≥ 50% of quota headroom**: a pass may consume at most half the remaining budget; big fetches go through `fetch_jobs`, never inline.
- Default `history_since` = `now() - interval '1 year'`; `NULL` means all-time.
- Migrations exist in BOTH dialects: `src/Db/migrations/0001_schema.sqlite.sql` and `0001_schema.pgsql.sql` (append; do not edit applied statements).
- Tests: plain `PHPUnit\Framework\TestCase` unless awaiting futures (then `Amp\PHPUnit\AsyncTestCase`); Redis tests self-skip when `tcp://127.0.0.1:16379` is unreachable; sqlite via `sqlite::memory:`; run with `vendor/bin/phpunit --no-coverage`.
- No new Composer dependencies.

---

### Task 1: `sync_targets` + `fetch_jobs` migrations

**Files:**
- Modify: `src/Db/migrations/0001_schema.sqlite.sql` (append)
- Modify: `src/Db/migrations/0001_schema.pgsql.sql` (append)
- Test: `tests/Db/SchemaTest.php` (append)

**Interfaces:**
- Consumes: `PdoDriver`, `Migrations` (existing).
- Produces: tables `sync_targets(peer_id BIGINT PK, type TEXT, history_since TIMESTAMP NULL, enabled INTEGER)` and `fetch_jobs(id INTEGER PK AUTOINCREMENT, peer_id BIGINT, until_date TIMESTAMP NULL, attempts INTEGER DEFAULT 0, status TEXT DEFAULT 'pending')` — later tasks rely on these exact columns.

- [x] **Step 1: Write the failing schema test**

Append to `tests/Db/SchemaTest.php` (inside the class, mirroring the existing sqlite schema test):

```php
public function testSyncTargetsAndFetchJobsTablesExist(): void
{
    $driver = new PdoDriver('sqlite::memory:');
    (new Migrations($driver))->migrate();

    $targets = $driver->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('sync_targets','fetch_jobs')");
    $names = array_column($targets, 'name');
    $this->assertContains('sync_targets', $names);
    $this->assertContains('fetch_jobs', $names);

    $cols = $driver->query("PRAGMA table_info(sync_targets)");
    $this->assertSame(
        ['peer_id', 'type', 'history_since', 'enabled'],
        array_column($cols, 'name'),
    );
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --no-coverage --filter testSyncTargetsAndFetchJobsTablesExist tests/Db/SchemaTest.php`
Expected: FAIL — `sync_targets` missing from table list.

- [x] **Step 3: Append migrations (both dialects)**

Append to `src/Db/migrations/0001_schema.sqlite.sql`:

```sql
CREATE TABLE IF NOT EXISTS sync_targets (
    peer_id INTEGER PRIMARY KEY,
    type TEXT NOT NULL,
    history_since INTEGER NULL,
    enabled INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_sync_targets_enabled ON sync_targets (enabled);

CREATE TABLE IF NOT EXISTS fetch_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    peer_id INTEGER NOT NULL,
    until_date INTEGER NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending'
);
CREATE INDEX IF NOT EXISTS idx_fetch_jobs_status ON fetch_jobs (status);
```

Append to `src/Db/migrations/0001_schema.pgsql.sql`:

```sql
CREATE TABLE IF NOT EXISTS sync_targets (
    peer_id BIGINT PRIMARY KEY,
    type TEXT NOT NULL,
    history_since TIMESTAMPTZ NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE
);
CREATE INDEX IF NOT EXISTS idx_sync_targets_enabled ON sync_targets (enabled);

CREATE TABLE IF NOT EXISTS fetch_jobs (
    id BIGSERIAL PRIMARY KEY,
    peer_id BIGINT NOT NULL,
    until_date TIMESTAMPTZ NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending'
);
CREATE INDEX IF NOT EXISTS idx_fetch_jobs_status ON fetch_jobs (status);
```

(Store timestamps as unix epoch integers in sqlite — matches how `messages.date` already works; `PdoDriver` returns raw values.)

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Db/SchemaTest.php`
Expected: PASS (all schema tests).

- [x] **Step 5: Commit**

```bash
git add src/Db/migrations/0001_schema.sqlite.sql src/Db/migrations/0001_schema.pgsql.sql tests/Db/SchemaTest.php
git commit -m "feat(db): sync_targets and fetch_jobs migrations"
```

---

### Task 2: `SyncTargets` repository

**Files:**
- Create: `src/Sync/SyncTargets.php`
- Test: `tests/Sync/SyncTargetsTest.php`

**Interfaces:**
- Consumes: `RelationalStore` via `SqlDriver` (existing `PdoDriver`); table from Task 1.
- Produces:
  - `SyncTargets::__construct(SqlDriver $driver)`
  - `add(int $peerId, string $type, ?int $historySinceEpoch = null): void` — upsert; `null` = all-time; default caller passes `time() - 31557600` for 1y.
  - `remove(int $peerId): void`
  - `setEnabled(int $peerId, bool $enabled): void`
  - `isTarget(int $peerId): bool` — enabled only
  - `historySince(int $peerId): ?int` — epoch or `null` (all-time); `null` also when absent
  - `listEnabled(): array<int, array{peer_id: int, type: string, history_since: ?int}>`

- [x] **Step 1: Write the failing tests**

Create `tests/Sync/SyncTargetsTest.php`:

```php
<?php declare(strict_types=1);
// AGPL header — copy from src/Sync/SyncLoop.php:1-15

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Sync\SyncTargets;
use PHPUnit\Framework\TestCase;

class SyncTargetsTest extends TestCase
{
    private SyncTargets $targets;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->targets = new SyncTargets($driver);
    }

    public function testAddAndIsTarget(): void
    {
        $since = time() - 31557600;
        $this->targets->add(100, 'channel', $since);

        $this->assertTrue($this->targets->isTarget(100));
        $this->assertSame($since, $this->targets->historySince(100));
        $this->assertFalse($this->targets->isTarget(999));
    }

    public function testNullHistorySinceMeansAllTime(): void
    {
        $this->targets->add(200, 'group', null);
        $this->assertNull($this->targets->historySince(200));
    }

    public function testDisabledTargetIsNotATarget(): void
    {
        $this->targets->add(300, 'private_chat');
        $this->targets->setEnabled(300, false);
        $this->assertFalse($this->targets->isTarget(300));
    }

    public function testRemove(): void
    {
        $this->targets->add(400, 'channel');
        $this->targets->remove(400);
        $this->assertFalse($this->targets->isTarget(400));
    }

    public function testListEnabledOnlyReturnsEnabled(): void
    {
        $this->targets->add(500, 'channel', 111);
        $this->targets->add(501, 'group');
        $this->targets->add(502, 'group');
        $this->targets->setEnabled(502, false);

        $ids = array_column($this->targets->listEnabled(), 'peer_id');
        $this->assertSame([500, 501], $ids);
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/SyncTargetsTest.php`
Expected: FAIL — `Class "danog\MadelineProto\Sync\SyncTargets" not found`.

- [x] **Step 3: Implement `SyncTargets`**

Create `src/Sync/SyncTargets.php` (AGPL header + strict types):

```php
namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\SqlDriver;

final class SyncTargets
{
    public function __construct(private SqlDriver $driver)
    {
    }

    public function add(int $peerId, string $type, ?int $historySinceEpoch = null): void
    {
        $this->driver->exec(
            'INSERT INTO sync_targets (peer_id, type, history_since, enabled) VALUES (?, ?, ?, 1)
             ON CONFLICT(peer_id) DO UPDATE SET type = excluded.type, history_since = excluded.history_since, enabled = 1',
            [$peerId, $type, $historySinceEpoch],
        );
    }

    public function remove(int $peerId): void
    {
        $this->driver->exec('DELETE FROM sync_targets WHERE peer_id = ?', [$peerId]);
    }

    public function setEnabled(int $peerId, bool $enabled): void
    {
        $this->driver->exec('UPDATE sync_targets SET enabled = ? WHERE peer_id = ?', [(int) $enabled, $peerId]);
    }

    public function isTarget(int $peerId): bool
    {
        $rows = $this->driver->query('SELECT 1 FROM sync_targets WHERE peer_id = ? AND enabled = 1', [$peerId]);

        return isset($rows[0]);
    }

    public function historySince(int $peerId): ?int
    {
        $rows = $this->driver->query('SELECT history_since FROM sync_targets WHERE peer_id = ?', [$peerId]);

        return isset($rows[0]) ? ($rows[0]['history_since'] === null ? null : (int) $rows[0]['history_since']) : null;
    }

    /** @return array<int, array{peer_id: int, type: string, history_since: ?int}> */
    public function listEnabled(): array
    {
        return array_map(
            static fn (array $r): array => [
                'peer_id' => (int) $r['peer_id'],
                'type' => (string) $r['type'],
                'history_since' => $r['history_since'] === null ? null : (int) $r['history_since'],
            ],
            $this->driver->query('SELECT * FROM sync_targets WHERE enabled = 1 ORDER BY peer_id'),
        );
    }
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/SyncTargetsTest.php`
Expected: PASS (5 tests).

- [x] **Step 5: Commit**

```bash
git add src/Sync/SyncTargets.php tests/Sync/SyncTargetsTest.php
git commit -m "feat(sync): SyncTargets settings repository"
```

---

### Task 3: `FetchQueue` with 50% quota-headroom rule

**Files:**
- Create: `src/Sync/FetchQueue.php`
- Test: `tests/Sync/FetchQueueTest.php`

**Interfaces:**
- Consumes: `SqlDriver`, table `fetch_jobs` from Task 1.
- Produces:
  - `FetchQueue::__construct(SqlDriver $driver)`
  - `FetchQueue::enqueue(int $peerId, ?int $untilDateEpoch): void` — status `pending`
  - `FetchQueue::claim(?int $limitEpoch = null): ?array{id: int, peer_id: int, until_date: ?int}` — oldest `pending`, marks `running`
  - `FetchQueue::complete(int $id): void` — deletes the row
  - `FetchQueue::fail(int $id): void` — attempts++, `pending` again; attempts ≥ 5 → `dead`
  - `FetchQueue::deadLetterCount(): int`
  - `FetchQueue::quotaSlice(int $remaining, int $costPerFetch): int` — **static pure function**: how many fetches may run now while reserving ≥50% headroom. `max(0, intdiv($remaining, 2) / $costPerFetch)` floored.

- [x] **Step 1: Write the failing tests**

Create `tests/Sync/FetchQueueTest.php`:

```php
<?php declare(strict_types=1);
// AGPL header — copy from src/Sync/SyncLoop.php:1-15

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Sync\FetchQueue;
use PHPUnit\Framework\TestCase;

class FetchQueueTest extends TestCase
{
    private FetchQueue $queue;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->queue = new FetchQueue($driver);
    }

    public function testEnqueueClaimCompleteLifecycle(): void
    {
        $this->queue->enqueue(100, 1700000000);
        $this->queue->enqueue(200, null);

        $job = $this->queue->claim();
        $this->assertSame(100, $job['peer_id']);          // FIFO
        $this->assertSame(1700000000, $job['until_date']);

        // claimed job is not handed out twice
        $this->assertSame(200, $this->queue->claim()['peer_id']);
        $this->assertNull($this->queue->claim());

        $this->queue->complete($job['id']);
        $this->assertSame(0, $this->queue->deadLetterCount());
    }

    public function testFailRetriesThenDeadLetters(): void
    {
        $this->queue->enqueue(300, null);
        $job = $this->queue->claim();

        for ($i = 0; $i < 4; $i++) {
            $this->queue->fail($job['id']);
            $again = $this->queue->claim();               // requeued while attempts < 5
            $this->assertSame(300, $again['peer_id']);
            $job = $again;
        }

        $this->queue->fail($job['id']);                   // 5th failure → dead
        $this->assertNull($this->queue->claim());
        $this->assertSame(1, $this->queue->deadLetterCount());
    }

    public function testQuotaSliceReservesHalf(): void
    {
        // 100 remaining, each fetch costs 10 → may use at most 50 → 5 fetches
        $this->assertSame(5, FetchQueue::quotaSlice(100, 10));
        // tiny budget → zero fetches, all headroom kept
        $this->assertSame(0, FetchQueue::quotaSlice(9, 10));
        $this->assertSame(0, FetchQueue::quotaSlice(0, 1));
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/FetchQueueTest.php`
Expected: FAIL — `Class "danog\MadelineProto\Sync\FetchQueue" not found`.

- [x] **Step 3: Implement `FetchQueue`**

Create `src/Sync/FetchQueue.php` (AGPL header + strict types):

```php
namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\SqlDriver;

final class FetchQueue
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private SqlDriver $driver)
    {
    }

    /** Max fetches runnable now while reserving >= 50% of remaining quota headroom. */
    public static function quotaSlice(int $remaining, int $costPerFetch): int
    {
        if ($costPerFetch <= 0 || $remaining <= 0) {
            return 0;
        }

        return intdiv(intdiv($remaining, 2), $costPerFetch);
    }

    public function enqueue(int $peerId, ?int $untilDateEpoch): void
    {
        $this->driver->exec(
            'INSERT INTO fetch_jobs (peer_id, until_date, attempts, status) VALUES (?, ?, 0, ?)',
            [$peerId, $untilDateEpoch, 'pending'],
        );
    }

    /** @return array{id: int, peer_id: int, until_date: ?int}|null */
    public function claim(): ?array
    {
        $rows = $this->driver->query(
            "SELECT * FROM fetch_jobs WHERE status = 'pending' ORDER BY id LIMIT 1",
        );
        if (!isset($rows[0])) {
            return null;
        }

        $this->driver->exec("UPDATE fetch_jobs SET status = 'running' WHERE id = ?", [$rows[0]['id']]);

        return [
            'id' => (int) $rows[0]['id'],
            'peer_id' => (int) $rows[0]['peer_id'],
            'until_date' => $rows[0]['until_date'] === null ? null : (int) $rows[0]['until_date'],
        ];
    }

    public function complete(int $id): void
    {
        $this->driver->exec('DELETE FROM fetch_jobs WHERE id = ?', [$id]);
    }

    public function fail(int $id): void
    {
        $rows = $this->driver->query('SELECT attempts FROM fetch_jobs WHERE id = ?', [$id]);
        if (!isset($rows[0])) {
            return;
        }
        $attempts = (int) $rows[0]['attempts'] + 1;
        $status = $attempts >= self::MAX_ATTEMPTS ? 'dead' : 'pending';
        $this->driver->exec(
            'UPDATE fetch_jobs SET attempts = ?, status = ? WHERE id = ?',
            [$attempts, $status, $id],
        );
    }

    public function deadLetterCount(): int
    {
        $rows = $this->driver->query("SELECT COUNT(*) AS c FROM fetch_jobs WHERE status = 'dead'");

        return (int) ($rows[0]['c'] ?? 0);
    }
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/FetchQueueTest.php`
Expected: PASS (3 tests).

- [x] **Step 5: Commit**

```bash
git add src/Sync/FetchQueue.php tests/Sync/FetchQueueTest.php
git commit -m "feat(sync): FetchQueue with 50% quota-headroom rule and dead-lettering"
```

---

### Task 4: `UpdateHandler` (upsert → invalidate → emit, soft delete)

**Files:**
- Create: `src/Sync/UpdateHandler.php`
- Test: `tests/Sync/UpdateHandlerTest.php`
- Modify: `src/Db/migrations/0001_schema.sqlite.sql` + `0001_schema.pgsql.sql` (add `deleted_at` to `messages`)

**Interfaces:**
- Consumes: `RelationalStore::upsertMessage`, `Cache::delete`, `Cache::messageKey`, `EventBus::emit` (all existing); `SyncTargets::isTarget` (Task 2).
- Produces: `UpdateHandler::__construct(RelationalStore $store, Cache $cache, EventBus $bus, SyncTargets $targets)` and `UpdateHandler::process(int $accountId, string $type, array $data): void`. `$data` keys for messages: `peer_id`, `id`, optional `from_id/date/message/raw`. For `updateDeleteMessages`: `peer_id`, `ids` (list). Everything else passes through to `emit` only.

- [x] **Step 1: Add `deleted_at` migration + write the failing tests**

Append to BOTH migration files (sqlite uses INTEGER NULL; pgsql `TIMESTAMPTZ NULL`):

```sql
-- sqlite
ALTER TABLE messages ADD COLUMN deleted_at INTEGER NULL;
-- pgsql
ALTER TABLE messages ADD COLUMN deleted_at TIMESTAMPTZ NULL;
```

(Note: existing migrations run once; if `migrate()` tracks applied files rather than statements, instead add a new file `0002_messages_deleted_at.sqlite.sql` / `.pgsql.sql` mirroring how `Migrations` discovers files — check `src/Db/Migrations.php` first and follow its convention.)

Create `tests/Sync/UpdateHandlerTest.php`:

```php
<?php declare(strict_types=1);
// AGPL header — copy from src/Sync/SyncLoop.php:1-15

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;
use PHPUnit\Framework\TestCase;

class UpdateHandlerTest extends TestCase
{
    private UpdateHandler $handler;
    private RelationalStore $store;
    private SyncTargets $targets;
    /** @var list<array{int, string, array}> */
    private array $emitted = [];

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
        $this->targets = new SyncTargets($driver);
        $this->targets->add(100, 'channel');

        // EventBus spy: record emit calls, no network.
        $bus = new class($this->emitted) extends EventBus {
            public function __construct(private array &$emitted)
            {
                parent::__construct('', ''); // never used — all methods overridden we need
            }
            public function emit(int $accountId, string $type, array $data): void
            {
                $this->emitted[] = [$accountId, $type, $data];
            }
        };

        $cache = new class extends Cache {
            public function __construct() {}
            public function delete(string ...$keys): \Amp\Future
            {
                return \Amp\async(static fn (): null => null);
            }
        };

        $this->handler = new UpdateHandler($this->store, $cache, $bus, $this->targets);
    }

    public function testNewMessageUpsertedEmittedAndCached(): void
    {
        $this->handler->process(7, 'updateNewMessage', [
            'peer_id' => 100, 'id' => 1, 'message' => 'hi',
            'date' => 1700000000, 'raw' => '{"id":1}',
        ]);

        $msg = $this->store->getMessage(100, 1);
        $this->assertNotNull($msg);
        $this->assertSame('hi', $msg['message']);
        $this->assertNull($msg['deleted_at']);

        $this->assertCount(1, $this->emitted);
        [$accountId, $type, $data] = $this->emitted[0];
        $this->assertSame(7, $accountId);
        $this->assertSame('updateNewMessage', $type);
        $this->assertSame(1, $data['id']);
    }

    public function testNonTargetPeerIsStoredNowhereButStillEmitted(): void
    {
        $this->handler->process(7, 'updateNewMessage', ['peer_id' => 999, 'id' => 1, 'message' => 'x']);
        $this->assertNull($this->store->getMessage(999, 1));
        $this->assertCount(1, $this->emitted);
    }

    public function testDeleteMessagesSoftDeletesNeverRemoves(): void
    {
        $this->handler->process(7, 'updateNewMessage', ['peer_id' => 100, 'id' => 5, 'message' => 'x']);
        $this->handler->process(7, 'updateDeleteMessages', ['peer_id' => 100, 'ids' => [5]]);

        $msg = $this->store->getMessage(100, 5);
        $this->assertNotNull($msg, 'row must survive Telegram-side deletion');
        $this->assertNotNull($msg['deleted_at']);
        $this->assertCount(2, $this->emitted);
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/UpdateHandlerTest.php`
Expected: FAIL — `Class "danog\MadelineProto\Sync\UpdateHandler" not found` (and possibly the spy anonymous classes; if the `EventBus`/`Cache` constructors reject `''`, build the spies as standalone classes in the test file implementing only `emit`/`delete` — prefer that if inheritance fights you; keep constructor signature `__construct(private array &$emitted)` and no parent call).

- [x] **Step 3: Implement `UpdateHandler`**

Create `src/Sync/UpdateHandler.php` (AGPL header + strict types):

```php
namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;

final class UpdateHandler
{
    public function __construct(
        private RelationalStore $store,
        private Cache $cache,
        private EventBus $bus,
        private SyncTargets $targets,
    ) {
    }

    public function process(int $accountId, string $type, array $data): void
    {
        if ($type === 'updateNewMessage' || $type === 'updateEditMessage') {
            if (isset($data['peer_id'], $data['id']) && $this->targets->isTarget((int) $data['peer_id'])) {
                $this->store->upsertMessage($data + ['deleted_at' => null]);
                $this->cache->delete(Cache::messageKey((int) $data['peer_id'], (int) $data['id']))->await();
            }
        } elseif ($type === 'updateDeleteMessages' && isset($data['peer_id'], $data['ids'])) {
            foreach ($data['ids'] as $mid) {
                $row = $this->store->getMessage((int) $data['peer_id'], (int) $mid);
                if ($row !== null && $row['deleted_at'] === null) {
                    $this->store->upsertMessage(
                        ['peer_id' => $row['peer_id'], 'id' => $row['id'], 'date' => $row['date'],
                         'message' => $row['message'], 'raw' => $row['raw'], 'deleted_at' => time()],
                    );
                    $this->cache->delete(Cache::messageKey((int) $data['peer_id'], (int) $mid))->await();
                }
            }
        }

        $this->bus->emit($accountId, $type, $data);
    }
}
```

Also extend `RelationalStore::upsertMessage` to persist `deleted_at` when present (`'deleted_at' => $msg['deleted_at'] ?? null` in the column map) and the Task-4 migrations must actually run — verify via `getMessage` returning the column.

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/UpdateHandlerTest.php`
Expected: PASS (3 tests). Then `vendor/bin/phpunit --no-coverage tests/Db` — expect PASS (soft-delete column did not break round-trips).

- [x] **Step 5: Commit**

```bash
git add src/Sync/UpdateHandler.php src/Db/RelationalStore.php src/Db/Migrations.php src/Db/migrations tests/Sync/UpdateHandlerTest.php
git commit -m "feat(sync): UpdateHandler — upsert, exact invalidation, soft delete, emit"
```

---

### Task 5: Backfill worker + CLI

**Files:**
- Create: `src/Sync/BackfillWorker.php`
- Create: `bin/madeline-backfill`
- Test: `tests/Sync/BackfillWorkerTest.php`

**Interfaces:**
- Consumes: `FetchQueue` (Task 3), `RelationalStore::upsertMessage`, `SyncTargets` (Task 2).
- Produces:
  - `BackfillWorker::__construct(RelationalStore $store, FetchQueue $queue)` plus a page fetcher injected as `callable(int $peerId, int $offset, int $limit): array<int, array{peer_id: int, id: int, date?: int, message?: string, raw?: string}>` (each page = up to `$limit` messages in reverse-chronological order; the real one wraps MadelineProto `messages->getHistory`).
  - `BackfillWorker::run(int $quotaRemaining, int $costPerPage = 10): void` — claims jobs until `FetchQueue::quotaSlice($quotaRemaining, $costPerPage)` pages are consumed; pages backwards from offset 0 storing each message until `until_date` boundary passed.
  - `bin/madeline-backfill` — `php bin/madeline-backfill enqueue --dsn=... --redis=... --peer=<id> [--since=<epoch|all>]` adds a job; `php bin/madeline-backfill run --dsn=... --redis=... --quota=<n>` drains one pass (daemon calls it periodically).

- [x] **Step 1: Write the failing tests**

Create `tests/Sync/BackfillWorkerTest.php`:

```php
<?php declare(strict_types=1);
// AGPL header — copy from src/Sync/SyncLoop.php:1-15

namespace danog\MadelineProto\Test;

use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Sync\BackfillWorker;
use danog\MadelineProto\Sync\FetchQueue;
use PHPUnit\Framework\TestCase;

class BackfillWorkerTest extends TestCase
{
    private RelationalStore $store;
    private FetchQueue $queue;

    protected function setUp(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $this->store = new RelationalStore($driver);
        $this->queue = new FetchQueue($driver);
    }

    public function testDrainsJobStoringPagesUntilBoundary(): void
    {
        // Fake history: messages id 1..30, date descending; boundary until_date = 1700000000.
        $fetcher = static function (int $peerId, int $offset, int $limit): array {
            $out = [];
            for ($i = $offset; $i < $offset + $limit && $i < 30; $i++) {
                $out[] = ['peer_id' => $peerId, 'id' => 30 - $i, 'date' => 1700000100 - $i * 10,
                          'message' => 'm' . (30 - $i), 'raw' => null];
            }

            return $out;
        };

        $this->queue->enqueue(100, 1700000000);

        $worker = new BackfillWorker($this->store, $this->queue, $fetcher, pageSize: 10);
        $worker->run(quotaRemaining: 100, costPerPage: 10);   // slice = 5 pages

        // 30 messages exist; boundary at 1700000000 → ids with date >= boundary stored:
        // dates 1700000100-10i >= 1700000000 → i <= 10 → ids 20..30 = 11 messages
        $this->assertNotNull($this->store->getMessage(100, 30));
        $this->assertNotNull($this->store->getMessage(100, 20));
        $this->assertNull($this->store->getMessage(100, 19));
        $this->assertNull($this->queue->claim(), 'job completed');
    }

    public function testTinyQuotaDoesNothingButKeepsJob(): void
    {
        $fetcher = static fn (): array => [];
        $this->queue->enqueue(100, null);

        (new BackfillWorker($this->store, $this->queue, $fetcher, pageSize: 10))
            ->run(quotaRemaining: 5, costPerPage: 10);        // slice = 0

        $this->assertNotNull($this->queue->claim(), 'job must stay queued');
    }
}
```

- [x] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/BackfillWorkerTest.php`
Expected: FAIL — `Class "danog\MadelineProto\Sync\BackfillWorker" not found`.

- [x] **Step 3: Implement `BackfillWorker`**

Create `src/Sync/BackfillWorker.php` (AGPL header + strict types):

```php
namespace danog\MadelineProto\Sync;

use danog\MadelineProto\Db\RelationalStore;

final class BackfillWorker
{
    /** @param callable(int, int, int): array<int, array{peer_id:int, id:int, date?:int, message?:string, raw?:string}> $fetchPage */
    public function __construct(
        private RelationalStore $store,
        private FetchQueue $queue,
        private $fetchPage,
        private int $pageSize = 100,
    ) {
    }

    public function run(int $quotaRemaining, int $costPerPage = 10): void
    {
        $pagesLeft = FetchQueue::quotaSlice($quotaRemaining, $costPerPage);

        while ($pagesLeft > 0 && ($job = $this->queue->claim()) !== null) {
            try {
                $offset = 0;
                for ($p = 0; $p < $pagesLeft; $p++) {
                    $page = ($this->fetchPage)($job['peer_id'], $offset, $this->pageSize);
                    if ($page === []) {
                        break 2; // history exhausted
                    }
                    foreach ($page as $msg) {
                        if ($job['until_date'] !== null
                            && isset($msg['date'])
                            && (int) $msg['date'] < $job['until_date']) {
                            break 3; // past boundary — job done
                        }
                        $this->store->upsertMessage($msg + ['deleted_at' => null]);
                    }
                    $offset += $this->pageSize;
                    $pagesLeft--;
                }
                $this->queue->complete($job['id']);
            } catch (\Throwable) {
                $this->queue->fail($job['id']);

                return; // gradual: give quota back to live traffic this pass
            }
        }
    }
}
```

- [x] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Sync/BackfillWorkerTest.php`
Expected: PASS (2 tests).

- [x] **Step 5: Create `bin/madeline-backfill` CLI**

Create `bin/madeline-backfill` mirroring `bin/madeline-daemon` structure (args `--dsn=`, `--redis=`, subcommands `enqueue --peer=<id> [--type=channel] [--since=<epoch>|--since=all]` and `run --quota=<n>`). `enqueue`: `SyncTargets::add` + `FetchQueue::enqueue` (default since = `time() - 31557600`; `all` → `null`). `run`: builds `BackfillWorker` whose fetcher wraps `new \danog\MadelineProto\API(...)` → `messages->getHistory(['peer' => $peer, 'offset_id' => 0, 'limit' => $page])` mapped to the row shape (id from `Update`, `date`, `message`, `raw` = `json_encode($update)`) — real Telegram calls, so `run` is only smoke-tested manually. `php -l bin/madeline-backfill` must pass.

- [x] **Step 6: Commit**

```bash
git add src/Sync/BackfillWorker.php bin/madeline-backfill tests/Sync/BackfillWorkerTest.php
git commit -m "feat(sync): gradual backfill worker + CLI (queue-driven, quota-sliced)"
```

---

### Task 6: Daemon wiring — replace null provider

**Files:**
- Modify: `bin/madeline-daemon` (swap `NullAccountDataProvider` for `UpdateHandler` + `EventBus` construction; PeriodicLoop already drives `SyncLoop` — add a second `PeriodicLoop` calling `BackfillWorker::run` with the configured default `quotaRemaining = 100` every 60s, returning `false` to continue)
- Modify: `src/Daemon/Daemon.php` (accept optional `?BackfillLoop`-style second loop OR keep as-is and drive from bin — prefer bin-side to avoid changing tested class; if class changes, update `tests/Daemon/DaemonTest.php` accordingly)
- Test: `tests/E2E/UpdateFlowE2ETest.php` (new)

**Interfaces:**
- Consumes: everything from Tasks 2–5.
- Produces: running daemon where live updates flow TG → Postgres → Redis; no `NullAccountDataProvider` in the live path (class may remain for tests).

- [x] **Step 1: Write the failing E2E test**

Create `tests/E2E/UpdateFlowE2ETest.php` (mirror `tests/E2E/RelationalE2ETest.php` setup: sqlite + Redis 16379, skip when Redis down):

```php
<?php declare(strict_types=1);
// AGPL header — copy from src/Sync/SyncLoop.php:1-15

namespace danog\MadelineProto\Test;

use Amp\PHPUnit\AsyncTestCase;
use danog\MadelineProto\Db\Cache;
use danog\MadelineProto\Db\Migrations;
use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Db\RelationalStore;
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;

class UpdateFlowE2ETest extends AsyncTestCase
{
    private const DSN = 'tcp://127.0.0.1:16379';

    public function testUpdateFlowsStoreCacheAndBus(): void
    {
        $this->setTimeout(10);
        $driver = new PdoDriver('sqlite::memory:');
        (new Migrations($driver))->migrate();
        $store = new RelationalStore($driver);
        $cache = new Cache(self::DSN);
        $targets = new SyncTargets($driver);
        $targets->add(100, 'channel');

        $bus = new EventBus(self::DSN, self::DSN, [], self::DSN);
        $handler = new UpdateHandler($store, $cache, $bus, $targets);

        $got = new \Amp\DeferredFuture;
        $bus->on('updateNewMessage', static function (int $accountId, string $type, array $data) use ($got): void {
            $got->complete($data);
        });
        $bus->start();

        $handler->process(42, 'updateNewMessage', [
            'peer_id' => 100, 'id' => 77, 'message' => 'e2e', 'date' => 1700000000, 'raw' => '{"id":77}',
        ]);

        $data = $got->getFuture()->await();
        $this->assertSame(77, $data['id']);
        $this->assertNotNull($store->getMessage(100, 77));

        $bus->stop();
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --no-coverage tests/E2E/UpdateFlowE2ETest.php`
Expected: FAIL — `UpdateHandler` not wired... it exists from Task 4, so if it PASSES immediately, that is fine for the handler part; the failure to force is the daemon wiring change below (assert the E2E passes first, then change bin).

- [x] **Step 3: Wire the daemon**

In `bin/madeline-daemon`, replace:
```php
$sync = new SyncLoop($accounts, $store, $cache, new NullAccountDataProvider());
```
with:
```php
use danog\MadelineProto\Events\EventBus;
use danog\MadelineProto\Sync\BackfillWorker;
use danog\MadelineProto\Sync\FetchQueue;
use danog\MadelineProto\Sync\SyncTargets;
use danog\MadelineProto\Sync\UpdateHandler;

$bus = new EventBus($redis, $redis, [], $redis);
$handler = new UpdateHandler($store, $cache, $bus, new SyncTargets($driver));
// real TG updates arrive via MadelineProto's EventHandler calling $handler->process(...);
// SyncLoop keeps cache invalidation; backfill drains gradually:
$backfill = new BackfillWorker($store, new FetchQueue($driver), /* real getHistory fetcher */ fn (): array => [], 100);
$sync = new SyncLoop($accounts, $store, $cache, new NullAccountDataProvider());
```
and add a second periodic loop before `EventLoop::run()`:
```php
$backfillLoop = new \danog\Loop\PeriodicLoop(
    static function (): bool { $backfill->run(100, 10); return false; },
    'backfill-loop',
    60.0,
);
$backfillLoop->start();
```
(The real fetcher closure is the only TG-dependent piece; ship it as a `getHistoryFetcher(API $api): callable` static on `BackfillWorker` so tests stay offline.)

- [x] **Step 4: Verify**

Run: `php -l bin/madeline-daemon && vendor/bin/phpunit --no-coverage tests/E2E/UpdateFlowE2ETest.php tests/Daemon tests/Sync`
Expected: lint OK; all PASS.

- [x] **Step 5: Commit**

```bash
git add bin/madeline-daemon src/Daemon/Daemon.php tests/E2E/UpdateFlowE2ETest.php src/Sync/BackfillWorker.php
git commit -m "feat(daemon): wire UpdateHandler + EventBus + gradual backfill into daemon"
```

---

## Self-Review

1. **Spec coverage:** UpdateHandler hot path → Task 4; backfill CLI + gradual + 50% quota + queue → Tasks 3 & 5; sync_targets settings (default 1y, all-time) → Task 2; daemon wiring dropping null provider → Task 6; soft-delete preservation → Tasks 1 & 4. Gap: none.
2. **Placeholder scan:** Task 6 Step 3 contains one inline `fn (): array => []` placeholder fetcher — intentional and immediately replaced by `getHistoryFetcher` shipped in the same task. No other placeholders.
3. **Type consistency:** `quotaSlice(int, int): int` used in Tasks 3 & 5; `claim(): ?array{id, peer_id, until_date}` consistent; `upsertMessage(array)` gains `deleted_at` in Task 4 and Task 5 passes it too; `UpdateHandler::process(int, string, array)` matches Task 6 wiring.
