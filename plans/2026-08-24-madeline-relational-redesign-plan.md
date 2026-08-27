# MadelineProto Relational Storage + Event-Bus — Implementation Plan

**Spec:** docs/superpowers/specs/2026-08-24-madeline-relational-redesign-design.md
**Branch:** v8
**Tech decisions (rulings, binding):**
- Long-term store: **PostgreSQL** via `pdo_pgsql`. Tests use **SQLite** (`pdo_sqlite`) behind the same `SqlDriver` interface.
- Cache + event bus: **`amphp/redis`** (already in vendor) — async, supports pub/sub (separation of subscriber vs control connection for hot reload).
- Single source of truth: one row per Telegram entity, shared across accounts via `account_entities` join.
- Public `EventHandler` API preserved; internals reimplemented on the bus.
- Daemon: a systemd service owning all accounts + Postgres + Redis. Replaces `proc_open` IPC worker.

Global Constraints:
- Values stored exactly as Telegram returns: `user_id`/`message_id`/`chat_id` are BIGINT, **never** auto-increment. `file_reference` stored as raw bytes (`BYTEA`/`BLOB`).
- At least one `api_id`/`api_hash` must exist before a session can attach.
- Cross-account update dedup: an update referencing the same `(peer_id, message_id)` or `pts` is stored once and dispatched once.
- Hot reload uses two Redis connections (A=pub/sub, B=control); reload must not drop subscriptions or restart the daemon.

---

## Task 1 — SQL migration system + relational schema

Create a tiny migration runner and the full DDL. Tables and **exact columns** (PostgreSQL types shown; SQLite uses equivalents: BIGINT→INTEGER, BYTEA→BLOB, JSONB→TEXT, NUMERIC→INTEGER/NUMERIC):

- **`accounts`** — `id` BIGINT PK (Telegram user_id of owner, NOT auto-increment), `api_id` BIGINT NOT NULL, `api_hash` TEXT NOT NULL, `session_blob` BYTEA, `auth_state` TEXT, `created_at` TIMESTAMPTZ DEFAULT now(), `updated_at` TIMESTAMPTZ DEFAULT now(). At least one row with `api_id`/`api_hash` must exist before a session can attach.
- **`users`** — `user_id` BIGINT PK (NOT auto-increment), `access_hash` NUMERIC, `username` TEXT NULL, `phone` TEXT NULL, `first_name` TEXT, `last_name` TEXT, `photo` JSONB, `bot` BOOL, `status` JSONB, `raw` JSONB (full verbatim TL object). Indexes: `username`, `phone`, `user_id`.
- **`chats`** — `id` BIGINT PK, `access_hash` NUMERIC, `title` TEXT, `username` TEXT NULL, `participants_count` INT, `photo` JSONB, `raw` JSONB. Indexes: `username`, `id`.
- **`channels`** — same shape as `chats`. Indexes: `username`, `id`.
- **`peers`** — `peer_id` BIGINT PK, `type` TEXT (`user`/`chat`/`channel`), `username` TEXT NULL, `phone` TEXT NULL. Unique indexes on `username`, `phone` for fast resolution.
- **`messages`** — `id` BIGINT, `peer_id` BIGINT, `from_id` BIGINT NULL, `date` BIGINT, `message` TEXT, `media` JSONB, `entities` JSONB, `raw` JSONB. Composite PK `(peer_id, id)` (message id unique per peer, NEVER auto-increment). Indexes: `(peer_id, id)`, `from_id`, `date`.
- **`dialogs`** — `account_id` BIGINT, `peer_id` BIGINT, `top_message` BIGINT, `unread_count` INT, `pts` BIGINT, PK `(account_id, peer_id)`.
- **`files`** — `volume_id` BIGINT, `local_id` BIGINT, `file_reference` BYTEA (verbatim), `type` TEXT. PK `(volume_id, local_id)`.
- **`account_entities`** — `account_id` BIGINT, `entity_id` BIGINT (user/chat/channel id), `relationship` TEXT, PK `(account_id, entity_id)`. Implements single-source-of-truth sharing.

All ids BIGINT, none auto-increment. `files.file_reference` stored as raw bytes. Heavy indexes as listed.

Deliverables: `src/Db/SqlDriver.php` (interface: `exec(string $sql)`, `query(...)`, `getDialect(): string`), `src/Db/PdoDriver.php` (Postgres+SQLite via PDO, dialect auto-detected from DSN), `src/Db/Migrations.php` (runner reading `src/Db/migrations/0001_schema.sql`, idempotent via a `_migrations` table, applies only if not already applied), `src/Db/migrations/0001_schema.sql` with both dialects (use a `-- @dialect pgsql` / `-- @dialect sqlite` sectioning the runner understands, or two files `0001_schema.pgsql.sql` + `0001_schema.sqlite.sql`). Keep it dependency-free (PDO only).

Write `tests/Db/SchemaTest.php`: migrate up on SQLite (in-memory or temp file), assert all 9 tables exist, assert `users.user_id` is the PK and NOT auto-increment (insert explicit id, read it back unchanged), assert `files.file_reference` round-trips raw bytes, assert indexes `username`/`phone`/`(peer_id,id)` exist. Also a Postgres variant gated behind an env (`MADLINE_PG=1`) that connects to `pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline` and runs the same assertions.

Acceptance: `vendor/bin/phpunit tests/Db/SchemaTest.php` passes. Migration runner is idempotent (re-running does not error).

## Task 2 — Relational storage repository

Create `src/Db/RelationalStore.php` (namespace `danog\MadelineProto\Db`) backed by `SqlDriver` (from Task 1). It is the single source of truth for Telegram data, storing values exactly as received. Method signatures:

```php
public function __construct(SqlDriver $driver);
// accounts
public function upsertAccount(int $id, int $apiId, string $apiHash, ?string $authState, ?string $sessionBlob = null): void;
public function getAccount(int $id): ?array;
public function listAccounts(): array;
// users / chats / channels
public function upsertUser(array $user): void;   // $user['user_id'] explicit; full TL in $user['raw'] (JSON)
public function getUser(int $id): ?array;
public function upsertChat(array $chat): void;
public function upsertChannel(array $channel): void;
public function getChat(int $id): ?array;
// peer resolution (single map)
public function resolvePeer(string $usernameOrPhone): ?array; // by username OR phone
public function indexPeer(int $peerId, string $type, ?string $username, ?string $phone): void;
// messages
public function upsertMessage(array $msg): void; // keyed by (peer_id, id); explicit ids
public function getMessage(int $peerId, int $id): ?array;
public function getMessagesBySender(int $fromId): array; // CROSS-ACCOUNT: all messages from a user across every account
// files
public function upsertFile(int $volumeId, int $localId, string $fileReferenceBytes, string $type): void;
public function getFile(int $volumeId, int $localId): ?array;
// single source of truth join
public function linkAccountEntity(int $accountId, int $entityId, string $relationship): void;
public function getAccountEntities(int $accountId): array;
```

Rules:
- All upserts are idempotent (INSERT ... ON CONFLICT DO UPDATE on the PK). Never auto-generate ids.
- Store the verbatim Telegram object under `raw` (JSON-encoded). Preserve exact values (access_hash, usernames, bytes) — do not transform.
- `resolvePeer` hits `peers` (username/phone unique indexes). `indexPeer` is called inside upsertUser/upsertChat/upsertChannel.
- `getMessagesBySender` must JOIN `messages.from_id` across all rows regardless of account — this is the query Telegram's API cannot do. Verify in test.

Write `tests/Db/RelationalStoreTest.php` (SQLite, no services): 
- upsertUser then getUser returns `raw` equal to input JSON; explicit user_id round-trips.
- upsert same user_id twice → exactly one `users` row.
- Two accounts link to the SAME user via `linkAccountEntity`; `getAccountEntities` returns both; `users` has 1 row.
- Two messages from the same `from_id` under two different accounts (different peer_id) → `getMessagesBySender($fromId)` returns both (cross-account).
- `getFile` round-trips raw `file_reference` bytes.

Acceptance: `vendor/bin/phpunit tests/Db/RelationalStoreTest.php` passes; no auto-increment; raw JSON preserved verbatim; cross-account query proven.

## Task 3 — Redis cache layer

Create the Redis cache layer using **`amphp/redis`** (already in vendor; `Amp\Redis\connect(...)` → `Amp\Redis\Redis`). Because MadelineProto runs on the amphp event loop, the cache is **async**; the synchronous `RelationalStore` (Task 2) is wrapped by an async `CachedStore`.

Deliverables:
- `src/Db/Cache.php` (namespace `danog\MadelineProto\Db`): constructor takes a `Redis` instance (or a DSN string it connects with). Methods (all return `Amp\Future`):
  - `get(string $key): Amp\Future` (resolves to string|null)
  - `set(string $key, string $value, ?int $ttlSeconds = null): Amp\Future`
  - `delete(string ...$keys): Amp\Future`
  - `exists(string $key): Amp\Future`
  - keys are namespaced: `entity:user:<id>`, `entity:chat:<id>`, `entity:channel:<id>`, `msg:<peer>:<id>`, `peer:<username|phone>`.
- `src/Db/CachedStore.php` (namespace `danog\MadelineProto\Db`): wraps a `RelationalStore`. Each read method: check `Cache`; on miss, read from `RelationalStore`, then `Cache::set` (TTL from a configured default, e.g. 300s); each upsert method: call `RelationalStore` upsert, then `Cache::delete` the relevant key(s) (invalidate). All methods async (`Amp\Future`). Provide a synchronous facade for tests via `Amp\wait()` if helpful.
- Tests `tests/Db/CacheTest.php` and `tests/Db/CachedStoreTest.php`:
  - Redis at `tcp://127.0.0.1:16379` (NO auth, already running for our work). If unreachable, mark test skipped with a clear message (don't fail the suite when redis is down).
  - Cache: set→get round-trips; TTL expiry (use a 1s TTL, sleep, assert null); delete removes key; namespacing correct.
  - CachedStore: on a fresh key, first read hits DB and populates cache (assert a second read returns same data without a DB change by using a spy/flag on RelationalStore, OR assert via redis that the key now exists); upsert invalidates the cache key (after upsert, cache key absent, next read re-fetches). Use the real `RelationalStore`+SQLite behind `CachedStore`.

GLOBAL CONSTRAINTS (binding):
- No new Composer dependencies (`amphp/redis` already present).
- Cache layer must NOT alter stored values; it only fronts `RelationalStore`.
- Invalidation must be exact (only the changed entity/key).
- Tests must be skippable when Redis is unavailable; must NOT require auth.

Acceptance: `vendor/bin/phpunit tests/Db/CacheTest.php tests/Db/CachedStoreTest.php` passes (with Redis on 16379). Cache hit served without DB round-trip; invalidation proven.

## Task 4 — Account manager (api_id/api_hash, login/logout)

Create `src/Accounts/AccountManager.php` (namespace `danog\MadelineProto\Accounts`), backed by `RelationalStore` (Task 2) for the `accounts` table. It owns the lifecycle of Telegram sessions and enforces the "≥1 api credential before login" invariant.

Design for testability: do NOT perform real Telegram network auth inside the manager. Instead accept an **injectable auth performer**: a `callable`/`Closure` `authPerformer(int $apiId, string $apiHash, ?string $sessionBlob): array` that returns `['user_id' => int, 'session_blob' => string, 'auth_state' => string]`. In production the daemon supplies a real performer that drives MTProto; in tests supply a fake one. This keeps the manager pure + unit-testable with no network.

Method signatures:
```php
public function __construct(RelationalStore $store, ?callable $authPerformer = null);
public function addApiCredentials(int $apiId, string $apiHash): void;   // persists (pending) credential; id filled on first successful login
public function hasCredentials(): bool;
public function requireCredentials(): void;  // throws \Exception if !hasCredentials()
public function login(int $apiId, string $apiHash, ?string $sessionBlob = null): int; // calls authPerformer, stores id+session_blob+auth_state, links account_entities, returns user_id
public function relogin(int $accountId): int; // re-attach using stored session_blob (no re-auth)
public function logout(int $accountId): void; // clears auth_state + session_blob
public function removeAccount(int $accountId): void;
public function listAccounts(): array;        // returns account rows (without session_blob ideally)
public function getAccount(int $accountId): ?array;
```

Rules:
- `login` MUST call `requireCredentials()` (or check the supplied api_id exists) first → if no credentials exist, throw.
- On successful login, `upsertAccount($userId, $apiId, $apiHash, $authState, $sessionBlob)` and `linkAccountEntity($userId, $userId, 'self')` (the account owns its own user row) — single source of truth.
- `relogin` reads stored `session_blob` and calls `authPerformer` with it (re-attach, no user interaction).
- `logout` clears `auth_state` + `session_blob` (keeps the credential row so the account can log in again).

Tests `tests/Accounts/AccountManagerTest.php` (SQLite + fake auth performer, no network):
- `addApiCredentials` then `hasCredentials()` true; empty store → `requireCredentials()` throws.
- `login` with fake performer returning user_id=777 + blob → `accounts` row persisted with id=777, auth_state set; `getAccount` returns it (session_blob present); `linkAccountEntity` created.
- `login` when no credentials → throws (invariant enforced).
- `relogin(777)` re-attaches using stored blob (fake performer receives the blob, returns same id).
- `logout(777)` clears auth_state + session_blob; `listAccounts` still lists it; `removeAccount` deletes it.

Acceptance: `vendor/bin/phpunit tests/Accounts/AccountManagerTest.php` passes; invariant "≥1 api credential before login" enforced; session_blob round-trips for relogin.

GLOBAL CONSTRAINTS (binding): no new Composer deps; PDO only via RelationalStore; PSR style + AGPL header.

## Task 5 — Background sync loop (store once, shared)

Create `src/Sync/SyncLoop.php` (namespace `danog\MadelineProto\Sync`) — the background sync that keeps the relational store current and is the proof of **single source of truth** (one entity row, many account links).

Design for testability: define an injectable **sync source** interface so no real MTProto/network is needed:
```php
interface AccountDataProvider {
    /** Returns the Telegram data for one logged-in account. */
    public function pull(int $accountId): array; // shape: ['user'=>array, 'messages'=>array[], 'peers'=>array[], 'chats'=>array[], 'channels'=>array[]]
}
```
`SyncLoop` depends on `AccountManager` (to enumerate accounts), `RelationalStore` (write once), `Cache` (invalidate), and `AccountDataProvider` (injected; real daemon supplies MTProto-backed provider, tests supply a fake).

```php
public function __construct(AccountManager $accounts, RelationalStore $store, Cache $cache, AccountDataProvider $provider, int $intervalSeconds = 30);
public function tick(): void;          // one sync pass over all accounts (callable manually in tests)
public function start(): void;        // wraps a PeriodicLoop on $intervalSeconds (amphp Loop)
public function stop(): void;
```

Rules:
- For each account from `AccountManager::listAccounts()`:
  - `pull($accountId)` → data.
  - `store->upsertUser($user)` (verbatim raw), `store->indexPeer(...)`, `store->upsertChat/upsertChannel`, `store->upsertMessage(...)` for each message.
  - `store->linkAccountEntity($accountId, $user['user_id'], 'self')` (and links for any peer the account knows).
  - After writes, `cache->delete(...)` the affected keys (user, messages, peers) so cache stays coherent.
- Because upserts are idempotent + keyed by Telegram ids, a user known to TWO accounts becomes ONE `users` row with TWO `account_entities` links — single source of truth.

Tests `tests/Sync/SyncLoopTest.php` (SQLite + real Redis 16379 + fake provider):
- Fake provider yields: account A and account B both reference the SAME user (id=777) plus 1 distinct message each (different peer_id, same from_id=777).
- Run `tick()` once.
- Assert: `users` has exactly 1 row (id 777); `account_entities` has 2 links (A→777, B→777); `messages` has 2 rows; `getMessagesBySender(777)` returns both (cross-account); affected cache keys were invalidated (assert keys absent in Redis after tick).
- Also assert idempotency: run `tick()` again → still 1 user row, 2 message rows (no duplicates).

Acceptance: `vendor/bin/phpunit tests/Sync/SyncLoopTest.php` passes (SQLite + Redis 16379); single source of truth + cache invalidation proven.

GLOBAL CONSTRAINTS (binding): no new Composer deps (amphp Loop already available); PDO via RelationalStore; Redis 16379 no auth; PSR style + AGPL header.

## Task 6 — Daemon bootstrap + systemd unit

Create `src/Daemon/Daemon.php` (namespace `danog\MadelineProto\Daemon`) — the proper systemd-managed daemon replacing the fragile `proc_open` IPC worker. It owns all account sessions + Postgres + Redis.

Design for testability: inject all dependencies (no hardcoded DSN/port in the Daemon class itself).

```php
final class Daemon {
    public function __construct(
        private readonly SqlDriver $driver,
        private readonly Cache $cache,
        private readonly AccountManager $accounts,
        private readonly SyncLoop $sync,
    );

    public function boot(): void;   // calls $sync->start(), sets up signal handlers (SIGTERM/SIGINT → stop)
    public function stop(): void;   // $sync->stop(), close driver, close redis; idempotent
    public function isRunning(): bool;
}
```

Entry point: `bin/madeline-daemon` (PHP CLI script, `#!/usr/bin/env php`, `declare(strict_types=1)`):
- Reads env vars or `--dsn=pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline` and `--redis=tcp://127.0.0.1:16379`.
- Accepts subcommands: `start` (default, boots + runs until SIGTERM), `stop` (sends SIGTERM to the PID file), `status` (checks if running).
- PID file: `/tmp/madeline-daemon.pid`.
- On SIGTERM/SIGINT: calls `$daemon->stop()`, removes PID, exits 0.

Systemd unit: `tools/systemd/madeline-daemon.service`:
```ini
[Unit]
Description=MadelineProto Daemon
After=network.target postgresql.service redis.service

[Service]
Type=simple
ExecStart=/usr/bin/php /path/to/bin/madeline-daemon start
ExecStop=/usr/bin/php /path/to/bin/madeline-daemon stop
PIDFile=/tmp/madeline-daemon.pid
Restart=always
RestartSec=5
Environment=MADLINE_DSN=pgsql:host=127.0.0.1;port=5432;dbname=madeline;user=madeline;password=madeline
Environment=MADLINE_REDIS=tcp://127.0.0.1:16379

[Install]
WantedBy=multi-user.target
```

Tests `tests/Daemon/DaemonTest.php` (SQLite + Redis 16379):
- Construct Daemon with PdoDriver (SQLite temp file), Cache, AccountManager, SyncLoop (with fake provider). Call `boot()`. Assert `isRunning() === true`. Call `stop()`. Assert `isRunning() === false`. Assert no resources leaked (driver closed, redis closed). Test signal handling by sending SIGTERM to current process in a child.

Acceptance: `vendor/bin/phpunit tests/Daemon/DaemonTest.php` passes; daemon boots, owns stores, clean shutdown; systemd unit present and syntactically valid (`systemd-analyze verify` if available, else syntax check).

GLOBAL CONSTRAINTS (binding): no new Composer deps; PSR style + AGPL header; replaces proc_open IPC worker (the zombie risk).

## Task 7 — Redis event bus dispatcher

Create `src/Events/EventBus.php` (namespace `danog\MadelineProto\Events`) — the core event dispatcher replacing the reflection-based handler map from `src/EventHandler.php:251`.

Design for testability: inject the Redis client(s) so no real network in unit tests.

```php
final class EventBus {
    public function __construct(
        private readonly RedisClient $publisher,  // connection for publishing (accounts emit here)
        private readonly RedisClient $subscriber, // connection A for subscribing (blocking listen)
        private readonly array $deduplicationTtl = ['messages' => 3600, 'service' => 300],
    );

    // Publish an update from one account (called by each account's update loop)
    public function emit(int $accountId, string $type, array $data): void;

    // Register a listener for an event type + filter (replaces reflection map)
    public function on(string $type, callable $handler, array $filter = []): void;

    // Start the dispatcher loop (connection A subscribes to all registered types)
    public function start(): void;

    // Stop the dispatcher (unsubscribes, closes connections)
    public function stop(): void;

    // Check if dispatcher is running
    public function isRunning(): bool;
}
```

Channels:
- `madeline:updates` — all updates flow here (connection A subscribes; accounts publish via the publisher connection).
- `madeline:sub:<type>` — per-type subscriber channels for hot reload (connection B control, see Task 9).

The dispatcher (connection A) subscribes to `madeline:updates`, decodes each message, and dispatches to all registered listeners whose filter matches the update. Listeners are stored in-memory as `[type => [callable, filter]]`.

Rules:
- One dispatcher, many accounts → single subscription, fan-in.
- The dispatcher does NOT re-publish; it calls listeners directly in-process.
- Filter is optional (null = all updates of that type).
- The publisher connection is separate from the subscriber connection to avoid blocking.

Tests `tests/Events/EventBusTest.php` (Redis 16379, no auth):
- Two fake accounts publish the same update type → bus delivers to a registered listener exactly once per publish (2 deliveries total).
- Listener receives the typed update (assert `$data['user_id'] === 777`).
- Unregistered update types are NOT delivered (no spurious dispatch).
- `stop()` disconnects cleanly.
- Skip if Redis unavailable.

Acceptance: `vendor/bin/phpunit tests/Events/EventBusTest.php` passes; one dispatcher, multi-account fan-in via pub/sub.

GLOBAL CONSTRAINTS (binding): no new Composer deps (amphp/redis already present); PSR style + AGPL header.

## Task 8 — Cross-account deduplication

Extend `EventBus` (from Task 7) with cross-account deduplication. The dispatcher must ensure an update that arrives on multiple accounts is dispatched **once** (not once per account) and stored **once** (single source of truth).

Dedup key computation (stable per update):
- Message updates: `"msg:{peer_id}:{message_id}"` (the message id is unique per peer).
- Service updates (e.g. `updateChatParticipant`): `"pts:{account_id}:{pts}"`.
- Fallback: `"update:{account_id}:{update_id}"` if neither available.

Implementation: extend `EventBus::emit()` to check a seen-set before dispatching. Use **Redis `SET` with TTL** (default 3600s for messages, 300s for service) — key `madeline:dedup:{key}`. If `SETNX` returns true (key did not exist), dispatch + store. If false (already seen), skip. Also maintain an in-memory `SplObjectStorage` or `array` for fast local checks within the same process (avoids a Redis round-trip for rapid bursts from the same account).

New methods on `EventBus`:
```php
public function isDuplicate(string $dedupKey): bool; // check Redis SET NX, set if not duplicate
public function setSeen(string $dedupKey, int $ttlSeconds): void; // mark seen in Redis
```

Tests `tests/Events/DedupTest.php` (Redis 16379):
- Three accounts emit identical `msg:100:42` update → exactly ONE delivery to a registered listener, not three.
- After the first delivery, a fourth account emitting the same `(peer, message_id)` → NO delivery (dedup hit).
- Different `(peer, message_id)` → NOT deduped (distinct key).
- Dedup TTL: after the Redis key expires (use a 2s TTL in test), the same update is delivered again.
- `isDuplicate` is idempotent: calling twice with same key → only one Redis SET.

Acceptance: `vendor/bin/phpunit tests/Events/DedupTest.php` passes; dedup verified across 3+ accounts; no double processing.

GLOBAL CONSTRAINTS (binding): no new Composer deps; PSR style + AGPL header.

## Task 9 — Hot reload (two Redis connections) + EventHandler reimplementation

Reimplement the handler registration from `src/EventHandler.php` (`internalStart` reflection map) to subscribe to the bus. Keep the **public API** (`onUpdate`, `#[Handler]`, `#[Cron]`, `#[Filter]`, `PluginEventHandler`, `SimpleEventHandler`) unchanged. Use **two Redis connections**:

- **Connection A** — subscriber-only (`RedisClient` in blocking listen mode). Subscribes to `madeline:updates` and **never** used for anything else, so subscriptions are never dropped by control traffic.
- **Connection B** — control channel (`RedisClient` used for register/unregister/reload). Never subscribes; only handles commands on `madeline:control`.

**Hot reload flow**:
1. Initial handler registration via connection B → in-memory registry updated.
2. Updates delivered via connection A to all registered handlers.
3. Reload: new handler registered via connection B → registry updated → connection A re-evaluates registry **without** dropping the subscription. Daemon does NOT restart.

Listeners register by event type (`#[Handler]`, `#[Cron]`, `#[Filter]`). Public `EventHandler` API unchanged; its internals now subscribe to the bus.

Test `tests/Events/HotReloadTest.php`:
- Start `EventBus` with real Redis on 16379.
- Register handler for `message` via control connection → assert triggers on next update pushed via publisher.
- Register second handler via control connection.
- Push update → both fire.
- No restart required; daemon continues running.
- Verify connection A never reconnects.

Acceptance: existing `EventHandler` tests pass against the bus; hot reload works with two connections.

## Task 10 — End-to-end verification + graph/semantic hooks

Create `tests/E2E/RelationalE2ETest.php` — the single test that proves the entire pipeline works end-to-end with real Postgres (127.0.0.1:5432, db=madeline) + Redis (127.0.0.1:16379).

Test steps (run within one PHPUnit test method or a small suite):
1. **Migrations up** on the real Postgres db (`MADLINE_PG=1`).
2. **Boot daemon**: construct `Daemon` with `PdoDriver` (Postgres), `Cache` (Redis 16379), `AccountManager`, `SyncLoop` (fake provider), `EventBus` (two connections). Call `boot()`.
3. **Account lifecycle**: add api credentials → `login(accountId=1)` + `login(accountId=2)` with fake auth performer (returns user_id=111 and 222). Assert `accounts` table has 2 rows, `hasCredentials()` true, `requireCredentials()` does not throw.
4. **Cross-account query**: upsert a shared user (id=777) via sync, then `RelationalStore::getMessagesBySender(777)` returns messages from BOTH accounts (proves single source of truth across accounts).
5. **Dedup**: three fake accounts emit identical `(peer=100, message_id=42)` → assert exactly ONE delivery to a registered listener.
6. **Hot reload**: register a handler for `message` → assert triggers; register a second handler via control channel → assert both fire; verify connection A never reconnects.
7. **Account invariant**: `requireCredentials()` throws when no credentials; `login` without credentials throws.

Extension point for **graph + semantic embeddings** (documented, no implementation):
- In `src/Db/migrations/0001_schema.sql`, ensure these columns exist for future AI work (already in schema):
  - `users.raw` JSONB — stores full Telegram object, can embed `embedding` array for semantic search.
  - `messages.raw` JSONB — same.
  - `account_entities` join table — allows building account-specific or global graphs (nodes = entities, edges = `account_entities` + `messages.from_id`/`peer_id`).
- Document the extension point in `docs/architecture/graph-embeddings-extension.md` (one paragraph, pointer to the columns).

Acceptance: `vendor/bin/phpunit tests/E2E/RelationalE2ETest.php` passes (real Postgres + Redis). Full new suite (`vendor/bin/phpunit tests/Db/ tests/Accounts/ tests/Sync/ tests/Events/ tests/E2E/`) is green. Extension point documented.

---

## Notes
- Every task: implementer commits on branch `v8`, writes a report, includes passing tests.
- No main/master work. Work happens on `v8`.
- Verification requires Postgres (5432) + `redis-server` (default port). A test bootstrap starts redis if missing and skips PG tests only if PG is unreachable.
