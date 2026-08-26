-- MadelineProto relational schema — SQLite dialect
-- Migration 0002: soft-delete column for messages (Task 4)
-- Telegram-side deletions never hard-delete rows, they stamp deleted_at

ALTER TABLE messages ADD COLUMN deleted_at INTEGER NULL;
