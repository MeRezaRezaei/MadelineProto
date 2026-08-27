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
