# Sync & Update Handler Design

**Goal:** Live Telegram updates drive the app (hot path via Redis EventBus); history is dumb background storage in Postgres, fetched gradually within quota, queryable even after Telegram deletes it.

## Components

### 1. UpdateHandler (hot path — the app's backbone)
- `src/Sync/UpdateHandler.php` — MadelineProto EventHandler.
- Every incoming update (message/edit/delete/service):
  1. Upsert into `RelationalStore` (Postgres) if its peer is a sync target.
  2. Invalidate the affected `Cache` keys (exact keys, existing pattern).
  3. `EventBus->emit($accountId, $type, $data)` — any part of the app registers via `EventBus->on()/controlRegister()`.
- Deletion updates: mark row deleted (soft) — **never hard-delete**; data must survive Telegram-side deletion.

### 2. Backfill CLI (history)
- `bin/madeline-backfill` — per enabled target: dialogs → `getHistory` paging backwards.
- Depth: `history_since` per target; **default = now − 1 year**; `all` supported (per-target flag `history_since = null` = all-time).
- Gradual fetch with quota safety:
  - Always reserve **≥ 50% of quota headroom** — never consume more than half the remaining flood budget per pass.
  - Big fetches go through a **fetch queue** (table `fetch_jobs`): job = (peer, until_date); worker drains queue gradually between live traffic.
- Store-once upserts (idempotent), so re-runs are safe.

### 3. Settings / targets
- New table `sync_targets`: `peer_id BIGINT PK, type ('channel'|'group'|'user'|'private_chat'), history_since TIMESTAMPTZ NULL (=all-time), enabled BOOL`.
- Nothing is stored unless a target is enabled. Default when adding: `history_since = now() - interval '1 year'`.

### 4. Daemon wiring
- Daemon replaces `NullAccountProvider` with `UpdateHandler` + `EventBus` startup.
- SyncLoop keeps existing cache-invalidation invariants.

## Data flow
- **Hot:** TG update → UpdateHandler → Postgres upsert + cache invalidate + Redis emit → app handlers react.
- **Cold:** query Postgres (`RelationalStore`/`CachedStore`) — only source of truth; deleted-on-TG content still present.

## Error handling
- Flood-wait: back off, leave queue for next pass (gradual progress, never quota exhaustion).
- Backfill job failures: retry with attempt counter, dead-letter after N attempts.

## Testing
- Unit: target filtering, quota-budget arithmetic (50% rule), queue draining order.
- E2E (existing pattern): fake provider + sqlite + Redis 16379; backfill tested against fake dialogs/history pages.
