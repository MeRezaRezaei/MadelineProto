# Telegram Backup Sink ("Gather") — Design Spec

## 1. Goal

Treat Telegram as a free, durable **backup sink** (not file storage). A Laravel
scheduler triggers a MySQL dump → produces an archive file; MadelineProto
uploads it to a **dedicated private Telegram channel**; a daemon **verifier**
loop confirms the channel keeps receiving new backups and **alerts admins if
one goes missing**. No restore in v1. No scheduler inside MadelineProto.

## 2. Guiding Principles

- **One bucket = one private channel.** A bucket is a named backup target.
- **Source is a local archive** (zip/tar) produced by an external dump process.
  MadelineProto does NOT generate dumps; it only stores them.
- **Upload is triggered externally** (Laravel calls our library). MadelineProto
  owns no cron/schedule; the only time-based work inside MadelineProto is the
  safety verifier.
- **Chunking is done in code.** Archives are stream-split into ordered parts
  (≤ 1.5 GB) and uploaded as separate document messages; part order/count is
  recorded. No pre-splitting on disk.
- **Provisioning is automated via the user account**, which can talk to
  BotFather and create channels. Bot token + `channel_id` live in the DB
  catalog, protected, and are never re-created.
- **Naming convention:** all entities prefixed `madeline…gather…<random>` so
  they are identifiable and never lost. Bot username gets a random middle
  string (Telegram forces the `…bot` suffix, so "madeline" is not always
  possible there — handled).
- **Topology is flexible:** one bot → many channels, or several bots → many
  channels with read-only access for hardening. The catalog supports all.

## 3. v1 Scope

- One bucket = one private channel (extensible to N).
- Source = local archive file (zip/tar).
- Upload triggered by external call → `BackupService::backup(bucketName, archivePath)`.
- Chunked upload (≤ 1.5 GB parts).
- Automated provisioning via user account (bot via BotFather, channel create,
  bot attached with post access).
- Verifier: daemon `PeriodicLoop` polls each bucket's channel for the latest
  message; if no new message since last check (stale), alert.
- Alert: send to configurable alert peer (default bot owner private chat;
  overridable per bucket).
- Catalog (`channel_id`, bot token ref, bucket config) stored in DB, protected,
  transactional.
- **No restore.** Upload only.

## 4. Architecture & Components

- `src/Backup/BackupBucket.php` — immutable value object / config for a bucket.
- `src/Backup/BackupProvisioner.php` — via user account: create/reuse backup bot
  through BotFather, create private channel `madeline-gather-<rand>`, attach bot
  with post access. Persists `channel_id` + bot reference into `backup_buckets`.
- `src/Backup/BackupService.php` — `backup(bucket, archivePath)`: stream-split
  archive into ≤ 1.5 GB parts, upload each as a document message to the channel
  via the bucket's bot account, record job in DB with strict state machine
  (`pending → uploading → completed/failed`). **Only mark `completed` after
  Telegram confirms all parts.**
- `src/Backup/BackupVerifier.php` — daemon `PeriodicLoop`: for each bucket, read
  the channel's latest message id since the last cursor; if no new message
  within `stale_after`, fire alert. Also alerts on jobs stuck in `uploading`.
- `src/Backup/AlertSender.php` — send alert message to the bucket's (or global)
  alert peer.
- `src/Db/RelationalStore.php` — new methods: `upsertBackupBucket`,
  `getBackupBucket`, `listBackupBuckets`, `deleteBackupBucket`,
  `insertBackupJob`, `updateBackupJob`, `getBackupJob`, `getLatestBackupJob`.
- `src/Db/migrations/0002_backup.pgsql.sql` + `0002_backup.sqlite.sql` — new
  tables.
- `bin/madeline-backup` CLI — `provision`, `upload <bucket> <archive>`,
  `verify`, `list`.
- Daemon wiring (`bin/madeline-daemon`): register `BackupVerifier` loop.
- `madeline-mcp` (optional, later): `storage.backup_status` tool — deferred.

## 5. Data Model (migration `0002_backup`)

### `backup_buckets`
| column | type | notes |
|--------|------|-------|
| id | BIGINT PK | auto/id |
| name | TEXT | e.g. `mysql-main` |
| channel_id | BIGINT NOT NULL | protected, single source of truth |
| channel_title | TEXT | `madeline-gather-<rand>` |
| bot_account_ref | TEXT | reference to accounts row / bot token |
| alert_peer | TEXT | peer to alert; empty = global default |
| check_interval | INT | verifier poll seconds, default 900 |
| stale_after | INT | alert if no new msg for this many seconds, default 3900 (~1h+ buffer) |
| created_at | TIMESTAMPTZ | default now() |

### `backup_jobs`
| column | type | notes |
|--------|------|-------|
| id | BIGINT PK | |
| bucket_id | BIGINT FK | |
| run_at | TIMESTAMPTZ | |
| status | TEXT | pending \| uploading \| completed \| failed |
| archive_name | TEXT | |
| size | BIGINT | |
| sha256 | TEXT | |
| part_count | INT | |
| message_ids | JSONB | array of msg ids in channel, in order |
| last_checked_message_id | BIGINT | verifier cursor |
| completed_at | TIMESTAMPTZ | |
| error | TEXT | |

## 6. Flows

### Upload (external call — Laravel → `BackupService::backup`)
1. Resolve bucket from DB (by name).
2. Insert `backup_jobs` row `status=pending`.
3. Open archive; stream in ≤ 1.5 GB chunks; for each chunk `sendDocument` to
   channel via bot account; collect `message_id`.
4. On all parts done: update row `status=completed`, `message_ids`,
   `completed_at`, `sha256`. **Transactional: completed only after Telegram
   confirms every part.**
5. On failure: `status=failed`, `error` set. Verifier catches staleness.

### Verify (daemon loop — `BackupVerifier`)
1. For each bucket: read channel's latest message (`messages.getHistory`
   limit 1) since `last_checked_message_id`.
2. If new message id > cursor → advance cursor, healthy.
3. If no new message AND (`now − last_completed_or_checked`) > `stale_after`
   → `AlertSender.send("Backup missing for bucket <name>")`.
4. Also: any job stuck in `uploading` beyond a timeout → alert.

## 7. Error Handling

- Partial upload → `failed`, never `completed`; next external call retries.
- Channel inaccessible / bot removed → verifier alerts.
- BotFather create fails → provisioner throws; admin creates manually and
  registers bucket with `(bot_token, channel_id)`.

## 8. Testing

- `BackupService` split logic with fake chunk sizes (unit).
- `RelationalStore` backup CRUD (unit, sqlite + pgsql).
- `BackupVerifier` alert trigger on staleness (fake MTProto channel).
- `BackupProvisioner` with stubbed BotFather / channel create.
- E2E against sqlite + fakes: full state machine (pending→uploading→completed)
  and alert-on-stale, no live network required.

## 9. Success Criteria

1. `bin/madeline-backup provision` creates a bot + channel and stores
   `channel_id` protected in DB.
2. `bin/madeline-backup upload <bucket> <archive>` splits + uploads and records
   a `completed` job only after Telegram confirmation.
3. `BackupVerifier` in the daemon alerts admins when a bucket's channel goes
   stale (no new message within `stale_after`).
4. All new unit/integration/E2E tests pass 100%.
