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
