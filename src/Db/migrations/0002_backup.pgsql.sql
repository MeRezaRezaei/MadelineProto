CREATE TABLE IF NOT EXISTS backup_sets (
  set_id TEXT PRIMARY KEY,
  channel_id BIGINT NOT NULL,
  salt_hex TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS snapshots (
  snapshot_id TEXT PRIMARY KEY,
  set_id TEXT NOT NULL,
  manifest_msg_id BIGINT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  file_count INTEGER NOT NULL,
  total_bytes BIGINT NOT NULL
);
CREATE TABLE IF NOT EXISTS backup_files (
  snapshot_id TEXT NOT NULL,
  path TEXT NOT NULL,
  size BIGINT NOT NULL,
  mtime BIGINT NOT NULL,
  sha256 TEXT NOT NULL,
  chunks_json TEXT NOT NULL,
  PRIMARY KEY (snapshot_id, path)
);
CREATE TABLE IF NOT EXISTS chunks (
  hash TEXT PRIMARY KEY,
  set_id TEXT NOT NULL,
  msg_id BIGINT NOT NULL,
  file_id TEXT NOT NULL,
  size BIGINT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
