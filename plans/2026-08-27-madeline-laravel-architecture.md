# Telegram MadelineProto to Laravel Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-platform MadelineProto's core into a decoupled, high-performance architecture featuring a pure headless MTProto protocol engine, a Redis Streams ingestion pipeline, a multi-tenant PostgreSQL schema mirror, and Laravel-native models and services.

**Architecture:** 
1. **Tier 1 (MTProto Worker):** Asynchronous protocol daemon on Revolt/Amp handling MTProto 2.0 network sockets, Diffie-Hellman handshakes, SRP 2FA math, and TL binary deserialization. Dispatches raw updates directly into Redis Streams and consumes outbound commands from Redis queues.
2. **Tier 2 (Laravel Platform):** PostgreSQL Telegram schema mirror using direct 64-bit Telegram entity IDs, soft-deletes (`deleted_at`) for safe message audit retention, Eloquent models, Mini App HMAC auth verification, and typed AI service facades.

**Tech Stack:** PHP 8.2+, Revolt EventLoop, AmPHP v3, PostgreSQL (JSONB, BIGINT, TIMESTAMPTZ), Redis (Streams & Queues), Laravel 11.x / Eloquent.

**Spec:** `docs/superpowers/specs/2026-08-27-madeline-laravel-architecture-design.md`

## Global Constraints
- Telegram Entity Primary Keys must use verbatim 64-bit Telegram IDs (e.g. `user_id BIGINT PRIMARY KEY`), NEVER auto-increment sequences.
- No raw binary files stored directly in database columns; file metadata and SHA-256 hashes only.
- Messages must never be hard-deleted on Telegram deletion updates; set `deleted_at = NOW()` to maintain audit integrity.
- MTProto socket loop must never be blocked by database queries or business logic; all updates must be offloaded to Redis Streams in <1ms.

---

### Task 1: Complete PostgreSQL & SQLite Telegram Schema Migrations

**Files:**
- Create: `database/migrations/0001_01_01_000010_create_telegram_schema_tables.php`
- Modify: `src/Db/migrations/0001_schema.pgsql.sql`
- Modify: `src/Db/migrations/0001_schema.sqlite.sql`
- Test: `tests/Db/SchemaMirrorTest.php`

**Interfaces:**
- Consumes: PostgreSQL 14+ / SQLite 3.35+
- Produces: Tables `tg_accounts`, `tg_users`, `tg_chats`, `tg_channels`, `tg_peers`, `tg_messages`, `tg_dialogs`, `tg_account_entities`

- [ ] **Step 1: Write the failing test for Schema Mirror tables & indexes**

```php
<?php

namespace danog\MadelineProto\Test\Db;

use danog\MadelineProto\Db\PdoDriver;
use PHPUnit\Framework\TestCase;

class SchemaMirrorTest extends TestCase
{
    public function testSchemaTablesAndIndexesExist(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        $pdo = $driver->getPdo();
        
        $expectedTables = [
            'tg_accounts', 'tg_users', 'tg_chats', 'tg_channels',
            'tg_peers', 'tg_messages', 'tg_dialogs', 'tg_account_entities'
        ];
        
        foreach ($expectedTables as $table) {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
            $this->assertNotEmpty($stmt->fetchAll(), "Table $table missing");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Db/SchemaMirrorTest.php`
Expected: FAIL with missing tables

- [ ] **Step 3: Implement the migration SQL and Laravel Migration class**

Write the complete SQL definitions with 64-bit Telegram IDs, JSONB raw fields, soft deletes, and unified peer indexes in `src/Db/migrations/0001_schema.pgsql.sql` and `src/Db/migrations/0001_schema.sqlite.sql`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Db/SchemaMirrorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Db/migrations/ tests/Db/SchemaMirrorTest.php
git commit -m "feat(schema): implement comprehensive PostgreSQL and SQLite Telegram schema mirror"
```

---

### Task 2: Decoupled Session Storage Port (Redis / Database Session Store)

**Files:**
- Create: `src/MTProtoSession/SessionStoreInterface.php`
- Create: `src/MTProtoSession/PdoSessionStore.php`
- Create: `src/MTProtoSession/RedisSessionStore.php`
- Test: `tests/MTProtoSession/SessionStoreTest.php`

**Interfaces:**
- Consumes: `PdoDriver`, `Amp\Redis\RedisClient`
- Produces: `SessionStoreInterface::loadSession(int $accountId): ?array`, `SessionStoreInterface::saveSession(int $accountId, array $sessionData): void`

- [ ] **Step 1: Write the failing test for Session Store interface and implementations**

```php
<?php

namespace danog\MadelineProto\Test\MTProtoSession;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\MTProtoSession\PdoSessionStore;
use PHPUnit\Framework\TestCase;

class SessionStoreTest extends TestCase
{
    public function testPdoSessionStoreSaveAndLoad(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        // run migrations
        $store = new PdoSessionStore($driver);
        
        $sessionData = ['dc_id' => 2, 'auth_key' => 'secret_key_123', 'seq_no' => 10];
        $store->saveSession(123456, $sessionData);
        
        $loaded = $store->loadSession(123456);
        $this->assertEquals($sessionData, $loaded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/MTProtoSession/SessionStoreTest.php`
Expected: FAIL with Class Not Found

- [ ] **Step 3: Implement `SessionStoreInterface` and `PdoSessionStore`**

Implement decoupled session loading and saving without requiring filesystem `.safe.php` locks.

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/MTProtoSession/SessionStoreTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MTProtoSession/ tests/MTProtoSession/
git commit -m "feat(session): add decoupled SessionStoreInterface and PdoSessionStore"
```

---

### Task 3: Redis Stream Update Ingestion & Publisher Pipeline

**Files:**
- Create: `src/Pipeline/UpdateStreamPublisherInterface.php`
- Create: `src/Pipeline/RedisUpdateStreamPublisher.php`
- Create: `src/Pipeline/InMemoryUpdateStreamPublisher.php`
- Test: `tests/Pipeline/UpdateStreamPublisherTest.php`

**Interfaces:**
- Consumes: Decoded TL Update objects from `UpdateHandler`
- Produces: `UpdateStreamPublisherInterface::publish(int $accountId, array $update): void`

- [ ] **Step 1: Write the failing test for Update Publisher**

```php
<?php

namespace danog\MadelineProto\Test\Pipeline;

use danog\MadelineProto\Pipeline\InMemoryUpdateStreamPublisher;
use PHPUnit\Framework\TestCase;

class UpdateStreamPublisherTest extends TestCase
{
    public function testPublishMessageUpdate(): void
    {
        $publisher = new InMemoryUpdateStreamPublisher();
        $update = [
            '_' => 'updateNewMessage',
            'message' => [
                '_' => 'message',
                'id' => 101,
                'peer_id' => ['_' => 'peerUser', 'user_id' => 999],
                'message' => 'Hello World',
                'date' => time()
            ]
        ];
        
        $publisher->publish(123456, $update);
        $events = $publisher->getEvents();
        
        $this->assertCount(1, $events);
        $this->assertEquals(123456, $events[0]['account_id']);
        $this->assertEquals('updateNewMessage', $events[0]['type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Pipeline/UpdateStreamPublisherTest.php`
Expected: FAIL with Class Not Found

- [ ] **Step 3: Implement Publisher interfaces and Redis Stream pipeline**

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Pipeline/UpdateStreamPublisherTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Pipeline/ tests/Pipeline/
git commit -m "feat(pipeline): implement Redis Stream update publisher for non-blocking MTProto loop"
```

---

### Task 4: Eloquent / Relational Telegram Models with Idempotent Upserts

**Files:**
- Create: `src/Entities/TelegramUser.php`
- Create: `src/Entities/TelegramChat.php`
- Create: `src/Entities/TelegramChannel.php`
- Create: `src/Entities/TelegramMessage.php`
- Create: `src/Entities/TelegramPeer.php`
- Create: `src/Entities/TelegramDialog.php`
- Create: `src/Entities/EntityManager.php`
- Test: `tests/Entities/EntityManagerTest.php`

**Interfaces:**
- Consumes: Structured TL Arrays from Ingestion Consumer
- Produces: `EntityManager::upsertUser(array $userTl)`, `EntityManager::upsertMessage(array $messageTl)`, `EntityManager::softDeleteMessages(int $peerId, array $msgIds)`

- [ ] **Step 1: Write the failing test for Entity Manager upserts and soft deletes**

```php
<?php

namespace danog\MadelineProto\Test\Entities;

use danog\MadelineProto\Db\PdoDriver;
use danog\MadelineProto\Entities\EntityManager;
use PHPUnit\Framework\TestCase;

class EntityManagerTest extends TestCase
{
    public function testUpsertMessageAndSoftDelete(): void
    {
        $driver = new PdoDriver('sqlite::memory:');
        $manager = new EntityManager($driver);
        
        $messageTl = [
            '_' => 'message',
            'id' => 500,
            'peer_id' => -1001234567890,
            'from_id' => 888777,
            'date' => 1700000000,
            'message' => 'Critical info',
            'entities' => []
        ];
        
        $manager->upsertMessage($messageTl);
        $saved = $manager->getMessage(-1001234567890, 500);
        $this->assertEquals('Critical info', $saved['message']);
        $this->assertNull($saved['deleted_at']);
        
        // Telegram sends delete update
        $manager->softDeleteMessages(-1001234567890, [500]);
        $deleted = $manager->getMessage(-1001234567890, 500);
        $this->assertNotNull($deleted['deleted_at'], 'Message must be soft deleted, not lost');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Entities/EntityManagerTest.php`
Expected: FAIL

- [ ] **Step 3: Implement Entity classes and `EntityManager` with `ON CONFLICT DO UPDATE`**

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Entities/EntityManagerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Entities/ tests/Entities/
git commit -m "feat(entities): implement typed Telegram entity models and safe soft-delete message manager"
```

---

### Task 5: Multi-Tenant 2FA State Machine & Authentication Gateway

**Files:**
- Create: `src/Auth/AuthStateMachine.php`
- Create: `src/Auth/AuthState.php`
- Create: `src/Auth/SrpPasswordVerifier.php`
- Test: `tests/Auth/AuthStateMachineTest.php`

**Interfaces:**
- Consumes: `AccountManager`, `PasswordCalculator`
- Produces: `AuthStateMachine::startPhoneLogin(string $phone, int $apiId, string $apiHash)`, `AuthStateMachine::submitCode(string $code)`, `AuthStateMachine::submitPassword(string $password)`

- [ ] **Step 1: Write the failing test for 2FA Auth State Machine**

```php
<?php

namespace danog\MadelineProto\Test\Auth;

use danog\MadelineProto\Auth\AuthStateMachine;
use danog\MadelineProto\Auth\AuthState;
use PHPUnit\Framework\TestCase;

class AuthStateMachineTest extends TestCase
{
    public function testStateMachineTransitions(): void
    {
        $sm = new AuthStateMachine();
        $this->assertEquals(AuthState::UNAUTHORIZED, $sm->getState());
        
        $sm->transitionTo(AuthState::PENDING_CODE);
        $this->assertEquals(AuthState::PENDING_CODE, $sm->getState());
        
        $sm->transitionTo(AuthState::PENDING_2FA);
        $this->assertEquals(AuthState::PENDING_2FA, $sm->getState());
        
        $sm->transitionTo(AuthState::ACTIVE);
        $this->assertEquals(AuthState::ACTIVE, $sm->getState());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Auth/AuthStateMachineTest.php`
Expected: FAIL

- [ ] **Step 3: Implement `AuthStateMachine` and SRP 2FA verification gateway**

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/Auth/AuthStateMachineTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Auth/ tests/Auth/
git commit -m "feat(auth): implement multi-tenant 2FA state machine and SRP verifier"
```

---

### Task 6: Telegram Mini App HMAC Authentication & AI Service Gateway

**Files:**
- Create: `src/MiniApp/MiniAppValidator.php`
- Create: `src/Services/TelegramService.php`
- Create: `src/Services/TelegramAccountScope.php`
- Test: `tests/MiniApp/MiniAppValidatorTest.php`
- Test: `tests/Services/TelegramServiceTest.php`

**Interfaces:**
- Consumes: Bot Token, Telegram Mini App `initData`, Ingestion Publisher
- Produces: `MiniAppValidator::validateInitData(string $initData, string $botToken): array`, `TelegramService::forAccount(int $accountId): TelegramAccountScope`

- [ ] **Step 1: Write the failing tests for Mini App HMAC validator and Service Gateway**

```php
<?php

namespace danog\MadelineProto\Test\MiniApp;

use danog\MadelineProto\MiniApp\MiniAppValidator;
use PHPUnit\Framework\TestCase;

class MiniAppValidatorTest extends TestCase
{
    public function testValidInitDataVerification(): void
    {
        $botToken = '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11';
        $userData = json_encode(['id' => 987654321, 'first_name' => 'Alice', 'username' => 'alice']);
        $authDate = (string)time();
        
        $params = [
            'auth_date' => $authDate,
            'query_id' => 'AAHdF6IQAAAAAN0XohBSnE5c',
            'user' => $userData
        ];
        ksort($params);
        
        $dataCheckString = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        $initDataString = http_build_query(array_merge($params, ['hash' => $hash]));
        
        $validator = new MiniAppValidator($botToken);
        $result = $validator->validate($initDataString);
        
        $this->assertTrue($result['valid']);
        $this->assertEquals(987654321, $result['user']['id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/MiniApp/MiniAppValidatorTest.php`
Expected: FAIL

- [ ] **Step 3: Implement `MiniAppValidator` and `TelegramService`**

- [ ] **Step 4: Run test to verify it passes**

Run: `php -dzend.assertions=1 ./vendor/bin/paratest -f tests/MiniApp/MiniAppValidatorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/MiniApp/ src/Services/ tests/MiniApp/ tests/Services/
git commit -m "feat(miniapp): add Mini App HMAC validation and AI agent TelegramService gateway"
```
