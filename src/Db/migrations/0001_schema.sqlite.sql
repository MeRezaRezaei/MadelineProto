-- MadelineProto relational schema — SQLite dialect
-- Migration 0001: base relational schema (single-source-of-truth).
-- NOTE: BIGINT (not INTEGER) is used for primary-key columns so SQLite does
-- NOT promote them to a rowid alias (i.e. they are NEVER auto-increment).

CREATE TABLE IF NOT EXISTS accounts (
    id          BIGINT PRIMARY KEY,           -- Telegram user_id of owner, NEVER auto-increment
    api_id      BIGINT NOT NULL,
    api_hash    TEXT NOT NULL,
    session_blob BLOB,
    auth_state  TEXT,
    created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at  TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    user_id            BIGINT PRIMARY KEY,     -- NEVER auto-increment
    access_hash        NUMERIC,
    username           TEXT NULL,
    phone              TEXT NULL,
    first_name         TEXT,
    last_name          TEXT,
    photo              TEXT,
    bot                INTEGER,
    status             TEXT,
    raw                TEXT                     -- full verbatim TL object
);
CREATE INDEX IF NOT EXISTS users_username   ON users (username);
CREATE INDEX IF NOT EXISTS users_phone      ON users (phone);
CREATE INDEX IF NOT EXISTS users_user_id    ON users (user_id);

CREATE TABLE IF NOT EXISTS chats (
    id                 BIGINT PRIMARY KEY,
    access_hash        NUMERIC,
    title              TEXT,
    username           TEXT NULL,
    participants_count INTEGER,
    photo              TEXT,
    raw                TEXT
);
CREATE INDEX IF NOT EXISTS chats_username ON chats (username);
CREATE INDEX IF NOT EXISTS chats_id       ON chats (id);

CREATE TABLE IF NOT EXISTS channels (
    id                 BIGINT PRIMARY KEY,
    access_hash        NUMERIC,
    title              TEXT,
    username           TEXT NULL,
    participants_count INTEGER,
    photo              TEXT,
    raw                TEXT
);
CREATE INDEX IF NOT EXISTS channels_username ON channels (username);
CREATE INDEX IF NOT EXISTS channels_id       ON channels (id);

CREATE TABLE IF NOT EXISTS peers (
    peer_id   BIGINT PRIMARY KEY,
    type      TEXT,                            -- user | chat | channel
    username  TEXT NULL,
    phone     TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS peers_username ON peers (username);
CREATE UNIQUE INDEX IF NOT EXISTS peers_phone    ON peers (phone);

CREATE TABLE IF NOT EXISTS messages (
    id         BIGINT,                         -- unique per peer, NEVER auto-increment
    peer_id    BIGINT,
    from_id    BIGINT NULL,
    date       BIGINT,
    message    TEXT,
    media      TEXT,
    entities   TEXT,
    raw        TEXT,
    PRIMARY KEY (peer_id, id)
);
CREATE INDEX IF NOT EXISTS messages_peer_id_id ON messages (peer_id, id);
CREATE INDEX IF NOT EXISTS messages_from_id    ON messages (from_id);
CREATE INDEX IF NOT EXISTS messages_date       ON messages (date);

CREATE TABLE IF NOT EXISTS dialogs (
    account_id   BIGINT,
    peer_id      BIGINT,
    top_message  BIGINT,
    unread_count INTEGER,
    pts          BIGINT,
    PRIMARY KEY (account_id, peer_id)
);

CREATE TABLE IF NOT EXISTS files (
    volume_id      BIGINT,
    local_id       BIGINT,
    file_reference BLOB,                       -- stored verbatim as raw bytes
    type           TEXT,
    PRIMARY KEY (volume_id, local_id)
);

CREATE TABLE IF NOT EXISTS account_entities (
    account_id   BIGINT,                       -- single-source-of-truth sharing
    entity_id    BIGINT,                       -- user/chat/channel id
    relationship TEXT,
    PRIMARY KEY (account_id, entity_id)
);

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
