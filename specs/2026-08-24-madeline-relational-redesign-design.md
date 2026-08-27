# MadelineProto Relational Storage + Event-Bus Redesign

**Date:** 2026-08-24
**Status:** Approved architecture (design draft)
**Scope:** Refactor *inside* MadelineProto (not a separate service).

## 1. Goal

Fix the architectural weaknesses identified in the current codebase:

1. One binary/blob store backs every logical database; they are caches, not a queryable model.
2. The event handler map is built once by reflection and cannot change at runtime (no hot reload).
3. IPC is a hidden, unsupervised `proc_open` worker (daemon-like but not a real daemon) that can orphan/zombie.

New target: a **single long-running systemd daemon** holding all accounts, with a **Postgres relational source of truth** (Telegram-exact values, heavily indexed) and **Redis** as cache + event bus with cross-account deduplication and two-connection hot reload. Existing public `EventHandler` API is preserved; its internals are reimplemented on the bus.

Design principle: **store Telegram's *results* verbatim, index them relationally** so we can answer queries Telegram's API cannot (e.g. "all messages from user X across all my accounts"), and keep one row per real entity shared by every logged-in account (single source of truth). This also unblocks future graph + semantic-embedding features for AI.

## 2. Architecture Overview

```
┌──────────────────────────────────────────────────────────┐
│                  madeline-daemon (systemd)                 │
│  owns: all account MTProto sessions, Postgres, Redis       │
│                                                            │
│  ┌────────────┐  ┌────────────────────┐  ┌─────────────┐  │
│  │ Account    │  │ Storage Orchestrator│  │ Event Bus   │  │
│  │ Manager    │  │ (sync loop, writes) │  │ Dispatcher  │  │
│  └────────────┘  └────────────────────┘  └─────────────┘  │
└───────┬───────────────────┬────────────────────┬──────────┘
        │                   │                    │
   Postgres (truth)    Redis (cache)       Redis pub/sub
        │                                      │
   ┌────┴─────┐                          ┌─────┴────────┐
   │ relational│                          │ listeners    │
   │ tables    │                          │ (EventHandler│
   └───────────┘                          │  registry)   │
                                           └──────────────┘
        ▲
        │ RPC client
┌───────┴────────┐
│ user apps /    │
│ madeline-mcp   │
└────────────────┘
```

## 3. Component: Daemon Layer

- **`madeline-daemon`** is a proper systemd service (unit file committed under `tools/systemd/`). systemd owns lifecycle: start, stop, restart, crash-reap. This removes the zombie risk inherent in the current `proc_open` IPC worker (`src/Ipc/Runner/ProcessRunner.php`).
- The daemon holds **all** account `MTProto` sessions in one process and owns the single Postgres connection pool + Redis connections.
- User applications and `madeline-mcp` connect as **clients** over a stable RPC interface (reuse/formalize the existing `danog\MadelineProto\API` client surface; replace the fragile IPC socket handshake with a supervised, versioned RPC).
- Graceful shutdown: on `SIGTERM`, flush sync, close Postgres/Redis, drain event bus.

## 4. Component: Storage (split)

### 4.1 Postgres — relational source of truth

New explicit relational schema, mirroring Telegram TL structures. Values stored **exactly** as Telegram returns them: no auto-increment IDs, raw bytes (e.g. `file_reference`) as `BYTEA`, `access_hash` preserved. Heavy indexing for the queries we want.

Representative tables (DDL lives in migration files, not here):

- **`accounts`** — `id` (BIGINT PK, Telegram user_id of the owning account), `api_id` (BIGINT), `api_hash` (TEXT), `session_blob` (BYTEA, the MTProto auth/session state), `auth_state` (TEXT), `created_at`, `updated_at`. At least one `api_id`/`api_hash` pair must exist before a session can be attached.
- **`users`** — `user_id` BIGINT PK (Telegram id, NOT auto-increment), `access_hash` NUMERIC, `username` TEXT NULL, `phone` TEXT NULL, `first_name`, `last_name`, `photo` (JSONB or BYTEA), `bot` BOOL, `status` JSONB, `raw` JSONB (full verbatim TL object for fidelity). Indexes: `username`, `phone`, `user_id`.
- **`chats`** / **`channels`** — `id` BIGINT PK, `access_hash` NUMERIC, `title`, `username` NULL, `participants_count`, `photo`, `raw` JSONB. Indexes on `username`, `id`.
- **`peers`** — resolve map: `peer_id` BIGINT PK, `type` TEXT (`user`/`chat`/`channel`), `username` NULL, `phone` NULL. Unique indexes enable fast `resolveUsername`/`resolvePhone`.
- **`messages`** — `id` BIGINT, `peer_id` BIGINT, `from_id` BIGINT NULL, `date` BIGINT, `message` TEXT, `media` JSONB, `entities` JSONB, `raw` JSONB. Composite PK `(peer_id, id)` (Telegram message id is unique per peer, never auto-increment). Indexes: `(peer_id, id)`, `from_id`, `date`.
- **`dialogs`** — `account_id` BIGINT, `peer_id` BIGINT, `top_message` BIGINT, `unread_count` INT, `pts` BIGINT, PK `(account_id, peer_id)`.
- **`files`** — `volume_id` BIGINT, `local_id` BIGINT, `file_reference` BYTEA (verbatim), `type` TEXT. PK `(volume_id, local_id)`. This replaces the `fileReferenceDb`.
- **`account_entities`** (membership join) — `account_id`, `entity_id` (user/chat/channel id), `relationship` TEXT (e.g. `contact`/`member`), PK `(account_id, entity_id)`. Implements single-source-of-truth sharing: one `users` row, many accounts point to it.

A new **SQL migration system** is added (lightweight migrator, no heavy ORM dependency introduced for writes; reads may use a thin query builder). Each logical DB previously in `src/Settings/DatabaseAbstract.php` (fileReference, min, username, fullPeer, peerInfo) maps onto the tables above.

### 4.2 Redis — cache + event bus

- **Cache:** explicit hot cache in front of Postgres (supersedes the in-ORM `cacheTtl` concept). Keyed by entity/id; TTL-configurable.
- **Event bus:** pub/sub channels for event dispatch + a control channel for hot reload (see §5).

## 5. Component: Event Layer (Redis)

- A **single general dispatcher** inside the daemon subscribes to updates from *all* accounts.
- **Cross-account deduplication:** an update that references the same Telegram entity/event is identified by a stable key (e.g. `(chat_id, message_id)`, or `pts`/`update_id` for service updates). It is **stored once** in Postgres and **dispatched once** to listeners — not once per account that received it. This is the fix for "won't send the same update to all accounts."
- **Listeners register by event type + filter.** The existing public `EventHandler` API (`onUpdate`, attribute filters, crons, `PluginEventHandler`) is **preserved**; its internals are reimplemented to subscribe to the bus instead of building a reflection map at startup (`src/EventHandler.php:251`). External code (plugins, `madeline-mcp`) registers the events it wants triggered.
- **Hot reload via two Redis connections:**
  - **Connection A** — pub/sub subscriber (blocking listen). Never used for anything else, so subscriptions are never dropped by control traffic.
  - **Connection B** — control channel: register/unregister handlers, push updated handler registries, trigger reload.
  - A reload updates the in-memory handler registry and re-dispatches; the daemon does **not** restart. This is the fix for the "static handler / no hot reload" weakness.

## 6. Component: Account Manager

- `api_id`/`api_hash` live in `accounts`. At least one credential set is required before any session can attach.
- Operations exposed through the daemon: `addApiCredentials`, `login` (user chooses which `api_id`/`api_hash` to use), `logout`, `removeAccount`. All persist to Postgres.
- Login flow: pick credentials → attach/initialize MTProto session → store `session_blob` + `auth_state` → begin sync.

## 7. Background Sync

- A sync loop per account pulls diffs from Telegram and writes once to Postgres (upsert by Telegram-exact keys). Keeps the relational store current without redundant API calls (the cost-saving goal).
- Cache invalidation: on write, invalidate the relevant Redis cache keys.

## 8. Phasing (implementation order)

- **P1 — Foundation:** Postgres relational schema + migration system; systemd daemon + lifecycle; Account Manager (api_id/api_hash, login/logout); Redis cache layer; RPC client surface. (Replaces the 5 logical blob DBs with one queryable model and kills the zombie IPC.)
- **P2 — Event bus:** Redis pub/sub dispatcher; cross-account dedup; two-connection hot reload; reimplement `EventHandler` internals on the bus (public API unchanged).
- **P3 — AI expansion:** graph + semantic-embedding columns/indexes over the relational data (out of scope for initial implementation, schema leaves room via `raw` JSONB + join tables).

## 9. Error Handling

- Postgres write failures: retry with backoff; on unrecoverable failure, pause that account's sync and alert via log/metric, do not crash the daemon.
- Redis down: cache misses fall through to Postgres; event bus degrades to in-process dispatch for connected accounts; control channel errors are logged and retried.
- Account auth expiry: `auth_state` transitions; daemon triggers re-login prompt via RPC.

## 10. Testing

- **Unit:** schema migrations apply/rollback; dedup key computation; membership join logic.
- **Integration:** daemon boots under systemd (or a test harness); multiple accounts receive the same group update → exactly one Postgres row + one dispatch; hot reload adds a listener without daemon restart; login/logout via RPC persists correctly.
- **Regression:** existing `EventHandler` filter/cron tests still pass against the bus reimplementation.
- **Performance:** indexed queries (by username, by `from_id` across accounts) return within budget; Redis cache hit-rate validated.

## 11. Out of Scope (initial)

- Full graph/semantic-embedding feature (P3).
- Breaking changes to the public `EventHandler` method signatures.
- Supporting non-Postgres long-term backends in the new relational model (Postgres is the single long-term store; Redis is cache-only).
