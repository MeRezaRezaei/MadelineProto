# Telegram MadelineProto to Laravel Architecture & PostgreSQL Schema Mirror Design

**Date:** 2026-08-27  
**Status:** Approved  
**Author:** AI Agent (Antigravity) & Lead Engineer  
**Scope:** Architectural Re-platforming of Telegram MTProto Protocol Engine to Laravel & PostgreSQL Distributed Architecture

---

## 1. Executive Summary & Problem Statement

### 1.1 The Problem with Legacy MadelineProto
MadelineProto is a feature-rich, asynchronous MTProto 2.0 client for PHP, but it suffers from severe architectural coupling:
1. **Reinvented In-House Framework:** Bundles custom, non-standard ORM/data access layers (`CachedStore`, `MemoryArray`, `CachedArray`, `RelationalStore`), custom raw SQL migrations (`0001_schema.pgsql.sql`, `0002_backup.pgsql.sql`), and custom process management (`Serialization.php`, `flock` file locking, ad-hoc IPC sockets).
2. **Fragile State Management:** Filesystem-based `.safe.php` binary serialization causes process lock contention, corruptions on ungraceful shutdown, and prevents horizontal scaling across distributed servers.
3. **Coupled Business Logic:** Protocol-level updates, database mutations, event handler execution, and network I/O all run on a single synchronous/asynchronous event loop thread, creating latency bottlenecks and risk of packet loss or PTS holes when complex tasks (e.g., AI inference, large database writes) execute.

### 1.2 The New Solution
Decouple the system into a **Two-Tier Architecture**:
- **Tier 1 (Protocol Engine):** A clean, headless MTProto client worker running on the Revolt / Amp event loop. Its sole responsibility is MTProto network transport, cryptography (Diffie-Hellman, 2FA SRP math), TL binary parsing, and PTS/sequence gap detection. It emits decoded updates to a **Redis Stream** and consumes outbound RPC commands from a **Redis Queue**.
- **Tier 2 (Laravel Platform):** A full-featured Laravel application that owns the **PostgreSQL Schema Mirror**, multi-account authentication state machines, business workflows, Telegram Mini App APIs, and AI Agent tool integrations.

---

## 2. System Architecture & Boundaries

```
┌─────────────────────────────────────────────────────────────────────────────────────────────┐
│                                 TELEGRAM SERVERS (MTProto 2.0)                              │
└───────────────────────────────────────────────┬─────────────────────────────────────────────┘
                                                │ TCP / Obfuscated / DoH
┌───────────────────────────────────────────────▼─────────────────────────────────────────────┐
│                                 TIER 1: MTPROTO WORKER DAEMON                               │
│  (Headless Protocol Engine • Revolt / Amp Event Loop • Stateless-Capable)                   │
│                                                                                             │
│  • Handshake & Session Cryptography (Diffie-Hellman, MTProto 2.0 IGE, SRP Math)             │
│  • TL (Type Language) Binary Parser (Decodes raw network packets into structured PHP arrays)│
│  • Sequence & PTS Gap Tracker (`pts`, `qts`, `seq`, auto-recovery via `getDifference`)     │
│  • Egress: Fast-path writes to Redis Stream (`tg:stream:updates`) in <1ms                   │
│  • Ingress: Consumes outbound RPC calls from Redis Queue (`tg:queue:commands:{account_id}`) │
└───────────────────────────────────────────────┬─────────────────────────────────────────────┘
                                                │ Redis Streams (Inbound) & Queues (Outbound)
┌───────────────────────────────────────────────▼─────────────────────────────────────────────┐
│                                  TIER 2: LARAVEL PLATFORM                                   │
│  (PostgreSQL Schema Mirror • Multi-Account Auth • Mini App Backend • AI Orchestration)     │
│                                                                                             │
│  • Ingest Consumer Pool: Reads Redis Stream ➔ Casts to DTOs ➔ Upserts PostgreSQL Mirror     │
│  • Safe Audit / Retention: Sets `deleted_at` on message deletion (never loses data)         │
│  • Zero Database Blobs: Stores metadata & SHA-256 hashes in DB (files in MinIO / S3)        │
│  • Multi-Account Manager: Phone + SMS/App Code + 2FA Cloud Password via State Machine      │
│  • Mini App Integration: Validates Telegram `initData` HMAC signatures                      │
│  • AI Agent Service: Clean typed interface (`TelegramService`) for autonomous workflows     │
└─────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Database Schema Specification (PostgreSQL Mirror)

All Telegram entities use **Telegram's 64-bit integer IDs as primary keys** (no synthetic auto-increment IDs for Telegram objects). Auto-increment (`BIGSERIAL`) is strictly reserved for internal queues and jobs.

### 3.1 `tg_accounts` (Multi-Tenant Account Sessions)
```sql
CREATE TABLE tg_accounts (
    id BIGINT PRIMARY KEY, -- Telegram User ID of logged-in account (or -api_id while pending)
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE, -- Platform User
    phone VARCHAR(32),
    api_id BIGINT NOT NULL,
    api_hash VARCHAR(64) NOT NULL,
    dc_id INT NOT NULL DEFAULT 2,
    auth_state VARCHAR(32) NOT NULL DEFAULT 'unauthorized', -- 'pending_code', 'pending_2fa', 'active', 'revoked'
    session_key_encrypted TEXT, -- AES-256 encrypted AuthKey string
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX idx_tg_accounts_user_id ON tg_accounts(user_id);
CREATE INDEX idx_tg_accounts_auth_state ON tg_accounts(auth_state);
```

### 3.2 `tg_users` (Telegram User Entities)
```sql
CREATE TABLE tg_users (
    id BIGINT PRIMARY KEY, -- Telegram user_id
    access_hash NUMERIC(20, 0),
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    username VARCHAR(128),
    phone VARCHAR(32),
    is_bot BOOLEAN NOT NULL DEFAULT FALSE,
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    is_premium BOOLEAN NOT NULL DEFAULT FALSE,
    status JSONB, -- UserStatus TL object
    photo JSONB, -- UserProfilePhoto metadata
    raw_attributes JSONB, -- Future-proof TL fields
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX idx_tg_users_username ON tg_users(LOWER(username)) WHERE username IS NOT NULL;
CREATE INDEX idx_tg_users_phone ON tg_users(phone) WHERE phone IS NOT NULL;
```

### 3.3 `tg_chats` & `tg_channels` (Groups and Broadcast Channels)
```sql
CREATE TABLE tg_chats (
    id BIGINT PRIMARY KEY, -- Basic group chat ID (positive)
    title VARCHAR(255) NOT NULL,
    participants_count INT DEFAULT 0,
    photo JSONB,
    raw_attributes JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE tg_channels (
    id BIGINT PRIMARY KEY, -- Channel / Supergroup ID (positive 64-bit integer)
    access_hash NUMERIC(20, 0),
    title VARCHAR(255) NOT NULL,
    username VARCHAR(128),
    participants_count INT DEFAULT 0,
    is_broadcast BOOLEAN NOT NULL DEFAULT FALSE,
    is_megagroup BOOLEAN NOT NULL DEFAULT FALSE,
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    photo JSONB,
    raw_attributes JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE INDEX idx_tg_channels_username ON tg_channels(LOWER(username)) WHERE username IS NOT NULL;
```

### 3.4 `tg_peers` (O(1) Unified Resolution Map)
```sql
CREATE TABLE tg_peers (
    peer_id BIGINT PRIMARY KEY, -- Unwrapped: +ID (User), -ID (Chat), -100... (Channel)
    type VARCHAR(16) NOT NULL, -- 'user', 'chat', 'channel'
    username VARCHAR(128),
    phone VARCHAR(32),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);
CREATE UNIQUE INDEX idx_tg_peers_username ON tg_peers(LOWER(username)) WHERE username IS NOT NULL;
CREATE UNIQUE INDEX idx_tg_peers_phone ON tg_peers(phone) WHERE phone IS NOT NULL;
```

### 3.5 `tg_messages` (Message Log with Audit Safe Soft-Delete)
```sql
CREATE TABLE tg_messages (
    id BIGINT NOT NULL, -- Message ID inside the chat
    peer_id BIGINT NOT NULL, -- Target Chat / User / Channel ID
    from_id BIGINT, -- Sender User / Channel ID
    date TIMESTAMPTZ NOT NULL,
    message TEXT,
    media_type VARCHAR(32), -- 'photo', 'document', 'video', 'voice', etc.
    media_hash VARCHAR(64), -- SHA-256 for deduplication
    media_meta JSONB, -- File reference, size, dimensions, mime_type, file_name
    reply_to_msg_id BIGINT,
    reply_to_peer_id BIGINT,
    entities JSONB, -- MessageEntity array (bold, italic, links, mentions)
    views INT,
    forwards INT,
    is_outgoing BOOLEAN NOT NULL DEFAULT FALSE,
    deleted_at TIMESTAMPTZ, -- Set on Telegram delete update; NEVER purged automatically
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (peer_id, id)
);
CREATE INDEX idx_tg_messages_sender ON tg_messages(from_id, date DESC);
CREATE INDEX idx_tg_messages_date ON tg_messages(peer_id, date DESC);
CREATE INDEX idx_tg_messages_media_hash ON tg_messages(media_hash) WHERE media_hash IS NOT NULL;
```

### 3.6 `tg_dialogs` & `tg_account_entities` (Dialog Tracking & Sharing)
```sql
CREATE TABLE tg_dialogs (
    account_id BIGINT NOT NULL REFERENCES tg_accounts(id) ON DELETE CASCADE,
    peer_id BIGINT NOT NULL,
    top_message_id BIGINT,
    unread_count INT NOT NULL DEFAULT 0,
    unread_mentions_count INT NOT NULL DEFAULT 0,
    pts BIGINT,
    is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (account_id, peer_id)
);

CREATE TABLE tg_account_entities (
    account_id BIGINT NOT NULL REFERENCES tg_accounts(id) ON DELETE CASCADE,
    entity_id BIGINT NOT NULL,
    relationship VARCHAR(32) NOT NULL, -- 'contact', 'member', 'admin', 'creator', 'self'
    created_at TIMESTAMPTZ DEFAULT NOW(),
    PRIMARY KEY (account_id, entity_id, relationship)
);
```

---

## 4. Multi-Tenant Authentication & 2FA State Machine

### 4.1 State Machine Lifecycle
```
[Unauthenticated]
       │
       ▼ (User submits phone, api_id, api_hash)
[pending_code] ──► auth.sendCode (MTProto DH handshake -> Telegram sends SMS/app code)
       │
       ▼ (User submits SMS/app code)
[auth.signIn]
       ├───► Success (No 2FA) ────────────────────────────────┐
       │                                                      │
       └───► SESSION_PASSWORD_NEEDED                          │
                  │                                           │
                  ▼                                           │
          [pending_2fa]                                       │
                  │                                           │
                  ▼ (User submits 2FA password)               │
          [account.getPassword + SRP Math + auth.checkPassword]
                  │                                           │
                  ▼ Success                                   │
                  └───────────────────────────────────────────┴──► [ACTIVE] (AuthKey encrypted & saved)
```

### 4.2 SRP 2FA Cryptographic Calculation
The MTProto worker uses the standard Telegram SRP specification (`PasswordCalculator`):
1. Computes $\text{PBKDF2-HMAC-SHA512}$ over salted password buffer (100,000 iterations).
2. Performs 2048-bit modular exponentiation $A = g^a \pmod p$, $S = (B - k \cdot g^x)^{a + u \cdot x} \pmod p$.
3. Generates $M_1$ proof and sends `inputCheckPasswordSRP` to `auth.checkPassword`.

---

## 5. Update Ingestion & Event Processing Pipeline

1. **MTProto Worker:**
   - Reads incoming MTProto packet from socket.
   - Unpacks TL constructor via compiled parser.
   - Writes directly to Redis Stream `tg:stream:updates` with payload:
     ```json
     {
       "account_id": 12345678,
       "type": "updateNewMessage",
       "pts": 1054,
       "payload": { ... }
     }
     ```
   - Responds to socket immediately (<1ms).

2. **Laravel Consumer Worker Pool:**
   - Reads batches from `tg:stream:updates` using Redis Consumer Groups.
   - Performs idempotent SQL upserts:
     - `INSERT INTO tg_users ... ON CONFLICT (id) DO UPDATE SET ...`
     - `INSERT INTO tg_messages ... ON CONFLICT (peer_id, id) DO UPDATE SET ...`
     - On `updateDeleteMessages`: `UPDATE tg_messages SET deleted_at = NOW() WHERE peer_id = ? AND id = ANY(?)`.
   - Dispatches Laravel domain events (`TelegramMessageReceived`, `TelegramChatMemberJoined`).

---

## 6. Telegram Mini App & AI Agent Integrations

### 6.1 Mini App Cryptographic Authentication
- The Telegram Web / Mini App provides `initData` query string to the Laravel API.
- Laravel validates `initData` using HMAC-SHA256:
  $$\text{secret\_key} = \text{HMAC-SHA256}(\text{"WebAppData"}, \text{bot\_token})$$
  $$\text{hash} = \text{HMAC-SHA256}(\text{data\_check\_string}, \text{secret\_key})$$
- Validated Telegram user ID maps directly to the user's platform session.

### 6.2 AI Agent Orchestration Interface
AI agents invoke Telegram actions via a clean, asynchronous Laravel service interface without holding socket locks:
```php
namespace App\Services\Telegram;

interface TelegramClientInterface
{
    public function forAccount(int $accountId): TelegramAccountScope;
}

interface TelegramAccountScope
{
    public function sendMessage(int|string $peer, string $text, array $options = []): MessageDto;
    public function searchMessages(int|string $peer, string $query, int $limit = 50): Collection;
    public function downloadMedia(int|string $peer, int $messageId, string $targetDisk = 'minio'): MediaDto;
}
```

---

## 7. Automated Layer Upgrade & Maintenance Workflow

When Telegram releases a new MTProto Layer (e.g. Layer 228+):
1. **Schema Ingestion:** Place new `TL_telegram_vXXX.tl` into schema directory.
2. **Layer Diff:** Run `tools/layerdiff.php` to generate structural diff of added/modified constructors.
3. **Database Migration:** Generate targeted Laravel migration for newly added fields.
4. **DTO Generation:** Compile typed PHP 8.3 Readonly DTOs and Enums.
5. **CI Pipeline:** Run automated end-to-end integration tests against SQLite / PostgreSQL test databases.
