CREATE DATABASE IF NOT EXISTS bandbrief
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bandbrief;

CREATE TABLE IF NOT EXISTS artists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    canonical_name VARCHAR(255) NOT NULL,
    canonical_name_normalized VARCHAR(255) NOT NULL,
    country_code CHAR(2) DEFAULT NULL,
    primary_genre VARCHAR(120) DEFAULT NULL,
    formed_year SMALLINT UNSIGNED DEFAULT NULL,
    disbanded_year SMALLINT UNSIGNED DEFAULT NULL,
    official_website_url VARCHAR(500) DEFAULT NULL,
    status ENUM('active', 'inactive', 'unknown') NOT NULL DEFAULT 'unknown',
    identity_confidence DECIMAL(5,2) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_artists_canonical_name_normalized (canonical_name_normalized),
    KEY idx_artists_canonical_name (canonical_name),
    KEY idx_artists_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ingestion_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED DEFAULT NULL,
    input_name VARCHAR(255) NOT NULL,
    input_name_normalized VARCHAR(255) NOT NULL,
    trigger_type ENUM('user_search', 'scheduled_refresh', 'manual_reingest') NOT NULL DEFAULT 'user_search',
    status ENUM('queued', 'running', 'partial', 'completed', 'failed', 'canceled') NOT NULL,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    requested_by VARCHAR(120) DEFAULT NULL,
    pipeline_version VARCHAR(40) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ingestion_runs_artist_created (artist_id, created_at),
    KEY idx_ingestion_runs_status_created (status, created_at),
    KEY idx_ingestion_runs_input_name_normalized (input_name_normalized),
    CONSTRAINT fk_ingestion_runs_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artist_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    alias_name VARCHAR(255) NOT NULL,
    alias_name_normalized VARCHAR(255) NOT NULL,
    alias_type ENUM('stage_name', 'translation', 'former_name', 'common_misspelling', 'other') NOT NULL DEFAULT 'other',
    source_name VARCHAR(64) DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    confidence DECIMAL(5,2) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_artist_aliases_artist_alias (artist_id, alias_name_normalized),
    KEY idx_artist_aliases_alias_name_normalized (alias_name_normalized),
    KEY idx_artist_aliases_source_name (source_name),
    CONSTRAINT fk_artist_aliases_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    source_name VARCHAR(64) NOT NULL,
    external_id VARCHAR(191) DEFAULT NULL,
    profile_url VARCHAR(500) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    followers_count BIGINT UNSIGNED DEFAULT NULL,
    popularity_score DECIMAL(8,3) DEFAULT NULL,
    profile_data_json JSON DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    last_synced_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_external_profiles_source_external (source_name, external_id),
    UNIQUE KEY uq_external_profiles_artist_source (artist_id, source_name),
    KEY idx_external_profiles_artist (artist_id),
    KEY idx_external_profiles_source_name (source_name),
    CONSTRAINT fk_external_profiles_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingestion_run_id BIGINT UNSIGNED NOT NULL,
    artist_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL,
    source_entity_type ENUM('artist', 'release', 'track', 'event', 'community', 'unknown') NOT NULL DEFAULT 'artist',
    source_entity_id VARCHAR(191) DEFAULT NULL,
    snapshot_status ENUM('ok', 'partial', 'error', 'empty') NOT NULL,
    http_status SMALLINT UNSIGNED DEFAULT NULL,
    fetched_at DATETIME NOT NULL,
    data_freshness_hours INT UNSIGNED DEFAULT NULL,
    payload_sha256 CHAR(64) DEFAULT NULL,
    raw_payload_json LONGTEXT DEFAULT NULL,
    parse_success TINYINT(1) NOT NULL DEFAULT 1,
    parse_error_message VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_snapshots_run_source_fetched (ingestion_run_id, source_name, fetched_at),
    KEY idx_source_snapshots_artist_source_fetched (artist_id, source_name, fetched_at),
    KEY idx_source_snapshots_status (snapshot_status),
    KEY idx_source_snapshots_source_entity (source_name, source_entity_id),
    CONSTRAINT fk_source_snapshots_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_source_snapshots_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    primary_snapshot_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) DEFAULT NULL,
    external_release_id VARCHAR(191) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    title_normalized VARCHAR(255) NOT NULL,
    release_type ENUM('album', 'single', 'ep', 'compilation', 'live', 'other') NOT NULL DEFAULT 'other',
    release_date DATE DEFAULT NULL,
    total_tracks INT UNSIGNED DEFAULT NULL,
    label_name VARCHAR(255) DEFAULT NULL,
    upc VARCHAR(32) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_releases_source_external (source_name, external_release_id),
    KEY idx_releases_artist_release_date (artist_id, release_date),
    KEY idx_releases_title_normalized (title_normalized),
    CONSTRAINT fk_releases_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_releases_snapshot
        FOREIGN KEY (primary_snapshot_id) REFERENCES source_snapshots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tracks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    release_id BIGINT UNSIGNED DEFAULT NULL,
    primary_snapshot_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) DEFAULT NULL,
    external_track_id VARCHAR(191) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    title_normalized VARCHAR(255) NOT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    track_number SMALLINT UNSIGNED DEFAULT NULL,
    disc_number SMALLINT UNSIGNED DEFAULT NULL,
    is_explicit TINYINT(1) NOT NULL DEFAULT 0,
    isrc VARCHAR(20) DEFAULT NULL,
    popularity_score DECIMAL(8,3) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tracks_source_external (source_name, external_track_id),
    KEY idx_tracks_artist_title_normalized (artist_id, title_normalized),
    KEY idx_tracks_release_track_number (release_id, track_number),
    CONSTRAINT fk_tracks_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_tracks_release
        FOREIGN KEY (release_id) REFERENCES releases(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_tracks_snapshot
        FOREIGN KEY (primary_snapshot_id) REFERENCES source_snapshots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    primary_snapshot_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL,
    external_event_id VARCHAR(191) DEFAULT NULL,
    event_name VARCHAR(255) DEFAULT NULL,
    venue_name VARCHAR(255) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(120) DEFAULT NULL,
    event_date DATE DEFAULT NULL,
    event_datetime DATETIME DEFAULT NULL,
    status ENUM('scheduled', 'canceled', 'completed', 'unknown') NOT NULL DEFAULT 'unknown',
    ticket_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_source_external (source_name, external_event_id),
    KEY idx_events_artist_event_date (artist_id, event_date),
    KEY idx_events_city_event_date (city, event_date),
    CONSTRAINT fk_events_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_events_snapshot
        FOREIGN KEY (primary_snapshot_id) REFERENCES source_snapshots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    ingestion_run_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL,
    metric_date DATE NOT NULL,
    followers_count BIGINT UNSIGNED DEFAULT NULL,
    listeners_monthly BIGINT UNSIGNED DEFAULT NULL,
    play_count BIGINT UNSIGNED DEFAULT NULL,
    engagement_rate DECIMAL(8,4) DEFAULT NULL,
    metric_payload_json JSON DEFAULT NULL,
    data_quality ENUM('high', 'medium', 'low', 'unknown') NOT NULL DEFAULT 'unknown',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_social_metrics_artist_source_date (artist_id, source_name, metric_date),
    KEY idx_social_metrics_source_date (source_name, metric_date),
    KEY idx_social_metrics_run (ingestion_run_id),
    CONSTRAINT fk_social_metrics_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_social_metrics_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_mentions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    ingestion_run_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL,
    mention_type ENUM('post', 'comment', 'thread', 'page', 'other') NOT NULL DEFAULT 'other',
    external_mention_id VARCHAR(191) DEFAULT NULL,
    mention_url VARCHAR(500) DEFAULT NULL,
    author_handle VARCHAR(120) DEFAULT NULL,
    title VARCHAR(500) DEFAULT NULL,
    body_excerpt TEXT DEFAULT NULL,
    mentioned_at DATETIME DEFAULT NULL,
    sentiment_label ENUM('positive', 'neutral', 'negative', 'mixed', 'unknown') NOT NULL DEFAULT 'unknown',
    sentiment_score DECIMAL(6,3) DEFAULT NULL,
    engagement_count INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_mentions_source_external (source_name, external_mention_id),
    KEY idx_community_mentions_artist_source_time (artist_id, source_name, mentioned_at),
    KEY idx_community_mentions_run (ingestion_run_id),
    CONSTRAINT fk_community_mentions_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_community_mentions_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS derived_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    ingestion_run_id BIGINT UNSIGNED NOT NULL,
    metric_key VARCHAR(100) NOT NULL,
    metric_value_decimal DECIMAL(18,6) DEFAULT NULL,
    metric_value_text VARCHAR(1000) DEFAULT NULL,
    metric_unit VARCHAR(40) DEFAULT NULL,
    metric_scope ENUM('artist', 'release', 'track', 'event', 'community', 'global') NOT NULL DEFAULT 'artist',
    calculation_version VARCHAR(40) NOT NULL DEFAULT 'v1',
    calculated_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_derived_metrics_run_key (ingestion_run_id, metric_key),
    KEY idx_derived_metrics_artist_key (artist_id, metric_key),
    KEY idx_derived_metrics_scope (metric_scope),
    CONSTRAINT fk_derived_metrics_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_derived_metrics_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_id BIGINT UNSIGNED NOT NULL,
    ingestion_run_id BIGINT UNSIGNED NOT NULL,
    report_version VARCHAR(40) NOT NULL DEFAULT 'v1',
    status ENUM('draft', 'final', 'superseded') NOT NULL DEFAULT 'final',
    bandbrief_score DECIMAL(6,2) DEFAULT NULL,
    confidence_score DECIMAL(6,2) DEFAULT NULL,
    coverage_score DECIMAL(6,2) DEFAULT NULL,
    summary_text TEXT DEFAULT NULL,
    report_json JSON DEFAULT NULL,
    generated_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_run_version (ingestion_run_id, report_version),
    KEY idx_reports_artist_generated (artist_id, generated_at),
    KEY idx_reports_status_generated (status, generated_at),
    CONSTRAINT fk_reports_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_reports_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_sections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    section_key VARCHAR(80) NOT NULL,
    section_title VARCHAR(255) NOT NULL,
    section_order SMALLINT UNSIGNED NOT NULL,
    content_markdown MEDIUMTEXT DEFAULT NULL,
    content_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_sections_report_key (report_id, section_key),
    UNIQUE KEY uq_report_sections_report_order (report_id, section_order),
    KEY idx_report_sections_section_key (section_key),
    CONSTRAINT fk_report_sections_report
        FOREIGN KEY (report_id) REFERENCES reports(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS score_breakdowns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    category_key VARCHAR(80) NOT NULL,
    raw_score DECIMAL(8,3) NOT NULL,
    weighted_score DECIMAL(8,3) NOT NULL,
    weight DECIMAL(6,3) NOT NULL,
    max_score DECIMAL(8,3) DEFAULT NULL,
    evidence_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_score_breakdowns_report_category (report_id, category_key),
    KEY idx_score_breakdowns_category_key (category_key),
    CONSTRAINT fk_score_breakdowns_report
        FOREIGN KEY (report_id) REFERENCES reports(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ingestion_run_id BIGINT UNSIGNED NOT NULL,
    artist_id BIGINT UNSIGNED DEFAULT NULL,
    source_snapshot_id BIGINT UNSIGNED DEFAULT NULL,
    source_name VARCHAR(64) NOT NULL,
    stage_name ENUM('resolve', 'fetch', 'normalize', 'derive', 'score', 'report', 'store') NOT NULL,
    error_code VARCHAR(80) NOT NULL,
    error_message TEXT NOT NULL,
    is_retryable TINYINT(1) NOT NULL DEFAULT 1,
    http_status SMALLINT UNSIGNED DEFAULT NULL,
    occurred_at DATETIME NOT NULL,
    context_json JSON DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_source_errors_run_stage (ingestion_run_id, stage_name),
    KEY idx_source_errors_source_occurred (source_name, occurred_at),
    KEY idx_source_errors_artist_occurred (artist_id, occurred_at),
    CONSTRAINT fk_source_errors_run
        FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_source_errors_artist
        FOREIGN KEY (artist_id) REFERENCES artists(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_source_errors_snapshot
        FOREIGN KEY (source_snapshot_id) REFERENCES source_snapshots(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
