ALTER TABLE source_snapshots
  ADD COLUMN IF NOT EXISTS source_role VARCHAR(20) NOT NULL DEFAULT 'identity' AFTER source,
  ADD COLUMN IF NOT EXISTS raw_payload_json JSON NULL AFTER payload_json,
  ADD COLUMN IF NOT EXISTS errors_json JSON NULL AFTER raw_payload_json,
  MODIFY COLUMN fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  DROP INDEX idx_source_snapshots_run_source,
  ADD INDEX idx_source_snapshots_run_source (ingestion_run_id, source, source_role);
