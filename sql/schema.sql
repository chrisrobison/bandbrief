CREATE DATABASE IF NOT EXISTS bandbrief CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bandbrief;

CREATE TABLE IF NOT EXISTS artists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canonical_name VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  resolver_confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_artists_canonical_name (canonical_name),
  KEY idx_artists_display_name (display_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS artist_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  alias VARCHAR(255) NOT NULL,
  source VARCHAR(50) NOT NULL,
  confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_artist_aliases_artist_alias (artist_id, alias),
  KEY idx_artist_aliases_source (source),
  CONSTRAINT fk_artist_aliases_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS external_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  external_id VARCHAR(255) NOT NULL,
  url TEXT NULL,
  username VARCHAR(255) NOT NULL DEFAULT '',
  payload_json JSON NULL,
  fetched_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_external_profiles_source_external_id (source, external_id),
  KEY idx_external_profiles_artist_source (artist_id, source),
  CONSTRAINT fk_external_profiles_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ingestion_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ingestion_runs_artist_started (artist_id, started_at),
  KEY idx_ingestion_runs_status (status),
  CONSTRAINT fk_ingestion_runs_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  status VARCHAR(32) NOT NULL,
  confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  collection_method VARCHAR(64) NOT NULL,
  payload_json JSON NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_source_snapshots_run_source (ingestion_run_id, source),
  KEY idx_source_snapshots_artist_source (artist_id, source),
  CONSTRAINT fk_source_snapshots_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_source_snapshots_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS releases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  release_date DATE NULL,
  release_type VARCHAR(64) NOT NULL DEFAULT '',
  url TEXT NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_releases_artist_date (artist_id, release_date),
  KEY idx_releases_run (ingestion_run_id),
  CONSTRAINT fk_releases_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_releases_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tracks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  duration_ms INT UNSIGNED NULL,
  track_number INT UNSIGNED NULL,
  external_id VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tracks_release (release_id),
  KEY idx_tracks_artist_source (artist_id, source),
  CONSTRAINT fk_tracks_release_id FOREIGN KEY (release_id) REFERENCES releases (id) ON DELETE CASCADE,
  CONSTRAINT fk_tracks_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  venue_name VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(128) NOT NULL DEFAULT '',
  country VARCHAR(128) NOT NULL DEFAULT '',
  starts_at DATETIME NULL,
  url TEXT NULL,
  metadata_json JSON NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_events_artist_date (artist_id, starts_at),
  KEY idx_events_run (ingestion_run_id),
  CONSTRAINT fk_events_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_events_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS social_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  metric_name VARCHAR(128) NOT NULL,
  metric_value DECIMAL(16,4) NOT NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_social_metrics_artist_source_metric (artist_id, source, metric_name),
  KEY idx_social_metrics_run (ingestion_run_id),
  CONSTRAINT fk_social_metrics_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_social_metrics_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS community_mentions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  external_id VARCHAR(255) NOT NULL,
  title VARCHAR(255) NOT NULL,
  url TEXT NULL,
  score INT NOT NULL DEFAULT 0,
  comment_count INT NOT NULL DEFAULT 0,
  mentioned_at DATETIME NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_community_mentions_artist_date (artist_id, mentioned_at),
  KEY idx_community_mentions_run (ingestion_run_id),
  CONSTRAINT fk_community_mentions_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_community_mentions_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS derived_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  metric_name VARCHAR(128) NOT NULL,
  metric_value DECIMAL(16,4) NOT NULL,
  metric_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_derived_metrics_artist_metric (artist_id, metric_name),
  KEY idx_derived_metrics_run (ingestion_run_id),
  CONSTRAINT fk_derived_metrics_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_derived_metrics_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  artist_id BIGINT UNSIGNED NOT NULL,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  bandbrief_score INT NOT NULL DEFAULT 0,
  confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
  report_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_artist_created (artist_id, created_at),
  KEY idx_reports_status (status),
  CONSTRAINT fk_reports_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_ingestion_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS report_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  section_name VARCHAR(100) NOT NULL,
  section_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_sections_report (report_id),
  CONSTRAINT fk_report_sections_report_id FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS score_breakdowns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(100) NOT NULL,
  score INT NOT NULL,
  weight DECIMAL(8,4) NOT NULL,
  weighted_score DECIMAL(10,4) NOT NULL,
  explanation TEXT NOT NULL,
  inputs_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_score_breakdowns_report (report_id),
  CONSTRAINT fk_score_breakdowns_report_id FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ingestion_run_id BIGINT UNSIGNED NOT NULL,
  artist_id BIGINT UNSIGNED NOT NULL,
  source VARCHAR(50) NOT NULL,
  error_code VARCHAR(100) NOT NULL,
  error_message TEXT NOT NULL,
  context_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_source_errors_run_source (ingestion_run_id, source),
  KEY idx_source_errors_artist (artist_id),
  CONSTRAINT fk_source_errors_run_id FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_source_errors_artist_id FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;
