# BandBrief MySQL Schema Notes

## 1) Table Explanations
- `artists`: canonical artist identity used as the anchor for all normalized data.
- `artist_aliases`: alternate names for identity resolution and search matching.
- `external_profiles`: source-specific profile links and IDs (Spotify, Last.fm, etc).
- `ingestion_runs`: top-level pipeline execution log for search/refresh jobs.
- `source_snapshots`: raw payload storage and fetch metadata for each source request.
- `releases`: normalized release-level catalog metadata.
- `tracks`: normalized track-level metadata linked to releases when available.
- `events`: normalized event listing records.
- `social_metrics`: periodic counts and rate-style metrics by source and date.
- `community_mentions`: mention-level records from Reddit/forums/community sources.
- `derived_metrics`: deterministic computed metrics produced from normalized data.
- `reports`: generated report snapshots and top-line scores.
- `report_sections`: structured narrative blocks composing a report.
- `score_breakdowns`: weighted category-level components of BandBrief Score.
- `source_errors`: stage/source errors retained even when run is partial.

## 2) Append-Only vs Current-State
- Append-only tables:
  - `ingestion_runs`
  - `source_snapshots`
  - `social_metrics`
  - `community_mentions`
  - `derived_metrics`
  - `reports`
  - `report_sections`
  - `score_breakdowns`
  - `source_errors`
- Current-state (upsert/update allowed):
  - `artists`
  - `artist_aliases`
  - `external_profiles`
  - `releases`
  - `tracks`
  - `events`

## 3) Example Rows as JSON
```json
{
  "artists": {
    "id": 1,
    "canonical_name": "The National",
    "canonical_name_normalized": "the national",
    "country_code": "US",
    "primary_genre": "indie rock",
    "formed_year": 1999,
    "disbanded_year": null,
    "official_website_url": "https://www.americanmary.com",
    "status": "active",
    "identity_confidence": 98.5,
    "created_at": "2026-04-12 10:00:00",
    "updated_at": "2026-04-12 10:00:00"
  },
  "artist_aliases": {
    "id": 10,
    "artist_id": 1,
    "alias_name": "National",
    "alias_name_normalized": "national",
    "alias_type": "common_misspelling",
    "source_name": "reddit",
    "is_primary": 0,
    "confidence": 72.0,
    "created_at": "2026-04-12 10:00:01",
    "updated_at": "2026-04-12 10:00:01"
  },
  "external_profiles": {
    "id": 21,
    "artist_id": 1,
    "source_name": "spotify",
    "external_id": "4F84IBURUo98rz4r61KF70",
    "profile_url": "https://open.spotify.com/artist/4F84IBURUo98rz4r61KF70",
    "display_name": "The National",
    "followers_count": 3650012,
    "popularity_score": 74.2,
    "profile_data_json": {"genres": ["indie rock"]},
    "is_verified": 1,
    "last_synced_at": "2026-04-12 10:05:00",
    "created_at": "2026-04-12 10:05:00",
    "updated_at": "2026-04-12 10:05:00"
  },
  "ingestion_runs": {
    "id": 300,
    "artist_id": 1,
    "input_name": "the national",
    "input_name_normalized": "the national",
    "trigger_type": "user_search",
    "status": "partial",
    "started_at": "2026-04-12 10:04:00",
    "completed_at": "2026-04-12 10:04:20",
    "requested_by": null,
    "pipeline_version": "v1",
    "notes": null,
    "created_at": "2026-04-12 10:04:00",
    "updated_at": "2026-04-12 10:04:20"
  },
  "source_snapshots": {
    "id": 500,
    "ingestion_run_id": 300,
    "artist_id": 1,
    "source_name": "wikipedia",
    "source_entity_type": "artist",
    "source_entity_id": "The_National_(band)",
    "snapshot_status": "ok",
    "http_status": 200,
    "fetched_at": "2026-04-12 10:04:03",
    "data_freshness_hours": 24,
    "payload_sha256": "4af9...",
    "raw_payload_json": "{\"title\":\"The National\"}",
    "parse_success": 1,
    "parse_error_message": null,
    "created_at": "2026-04-12 10:04:03",
    "updated_at": "2026-04-12 10:04:03"
  },
  "releases": {
    "id": 100,
    "artist_id": 1,
    "primary_snapshot_id": 500,
    "source_name": "spotify",
    "external_release_id": "2XYZ",
    "title": "First Two Pages of Frankenstein",
    "title_normalized": "first two pages of frankenstein",
    "release_type": "album",
    "release_date": "2023-04-28",
    "total_tracks": 11,
    "label_name": "4AD",
    "upc": "191400000000",
    "created_at": "2026-04-12 10:04:10",
    "updated_at": "2026-04-12 10:04:10"
  },
  "tracks": {
    "id": 900,
    "artist_id": 1,
    "release_id": 100,
    "primary_snapshot_id": 500,
    "source_name": "spotify",
    "external_track_id": "7ABC",
    "title": "Eucalyptus",
    "title_normalized": "eucalyptus",
    "duration_ms": 262000,
    "track_number": 1,
    "disc_number": 1,
    "is_explicit": 0,
    "isrc": "US4AD2300001",
    "popularity_score": 62.1,
    "created_at": "2026-04-12 10:04:11",
    "updated_at": "2026-04-12 10:04:11"
  },
  "events": {
    "id": 700,
    "artist_id": 1,
    "primary_snapshot_id": 500,
    "source_name": "songkick",
    "external_event_id": "SK123",
    "event_name": "The National Live",
    "venue_name": "Madison Square Garden",
    "city": "New York",
    "region": "NY",
    "country": "US",
    "event_date": "2026-09-10",
    "event_datetime": "2026-09-10 20:00:00",
    "status": "scheduled",
    "ticket_url": "https://example.com/tickets",
    "created_at": "2026-04-12 10:04:12",
    "updated_at": "2026-04-12 10:04:12"
  },
  "social_metrics": {
    "id": 800,
    "artist_id": 1,
    "ingestion_run_id": 300,
    "source_name": "spotify",
    "metric_date": "2026-04-12",
    "followers_count": 3650012,
    "listeners_monthly": 7812300,
    "play_count": null,
    "engagement_rate": null,
    "metric_payload_json": {"source_confidence": 0.98},
    "data_quality": "high",
    "created_at": "2026-04-12 10:04:13",
    "updated_at": "2026-04-12 10:04:13"
  },
  "community_mentions": {
    "id": 810,
    "artist_id": 1,
    "ingestion_run_id": 300,
    "source_name": "reddit",
    "mention_type": "post",
    "external_mention_id": "t3_abcd",
    "mention_url": "https://reddit.com/r/indieheads/...",
    "author_handle": "musicfan42",
    "title": "New The National track discussion",
    "body_excerpt": "...",
    "mentioned_at": "2026-04-10 12:34:00",
    "sentiment_label": "positive",
    "sentiment_score": 0.61,
    "engagement_count": 147,
    "created_at": "2026-04-12 10:04:14",
    "updated_at": "2026-04-12 10:04:14"
  },
  "derived_metrics": {
    "id": 9000,
    "artist_id": 1,
    "ingestion_run_id": 300,
    "metric_key": "release_velocity_12m",
    "metric_value_decimal": 1.000000,
    "metric_value_text": null,
    "metric_unit": "releases_per_year",
    "metric_scope": "artist",
    "calculation_version": "v1",
    "calculated_at": "2026-04-12 10:04:16",
    "created_at": "2026-04-12 10:04:16",
    "updated_at": "2026-04-12 10:04:16"
  },
  "reports": {
    "id": 10000,
    "artist_id": 1,
    "ingestion_run_id": 300,
    "report_version": "v1",
    "status": "final",
    "bandbrief_score": 77.40,
    "confidence_score": 84.20,
    "coverage_score": 79.00,
    "summary_text": "The artist shows strong reach with stable momentum.",
    "report_json": {"sections": ["overview", "score"]},
    "generated_at": "2026-04-12 10:04:18",
    "created_at": "2026-04-12 10:04:18",
    "updated_at": "2026-04-12 10:04:18"
  },
  "report_sections": {
    "id": 11000,
    "report_id": 10000,
    "section_key": "overview",
    "section_title": "Overview",
    "section_order": 1,
    "content_markdown": "Reach remains strong...",
    "content_json": {"bullets": ["Strong monthly listeners"]},
    "created_at": "2026-04-12 10:04:18",
    "updated_at": "2026-04-12 10:04:18"
  },
  "score_breakdowns": {
    "id": 12000,
    "report_id": 10000,
    "category_key": "reach",
    "raw_score": 82.000,
    "weighted_score": 32.800,
    "weight": 0.400,
    "max_score": 100.000,
    "evidence_json": {"monthly_listeners": 7812300},
    "created_at": "2026-04-12 10:04:18",
    "updated_at": "2026-04-12 10:04:18"
  },
  "source_errors": {
    "id": 13000,
    "ingestion_run_id": 300,
    "artist_id": 1,
    "source_snapshot_id": null,
    "source_name": "reddit",
    "stage_name": "fetch",
    "error_code": "timeout",
    "error_message": "Request exceeded timeout threshold",
    "is_retryable": 1,
    "http_status": null,
    "occurred_at": "2026-04-12 10:04:07",
    "context_json": {"timeout_seconds": 8},
    "created_at": "2026-04-12 10:04:07",
    "updated_at": "2026-04-12 10:04:07"
  }
}
```

## 4) Indexing Strategy
- Identity and uniqueness:
  - Unique keys on canonical artist names, artist aliases per artist, source external IDs.
- Query-by-artist paths:
  - Composite indexes with `artist_id` + date/time for releases/events/social/mentions/reports.
- Time-series and recency:
  - Indexes on `fetched_at`, `metric_date`, `mentioned_at`, `generated_at`, `occurred_at`.
- Run diagnostics:
  - Indexes on `ingestion_run_id` and `(ingestion_run_id, stage_name)` for troubleshooting.
- Scoring/report read performance:
  - `report_id` uniqueness for sections and score breakdown categories.

## 5) Nullable vs Required Fields
- Required:
  - Primary identifiers, foreign keys for ownership, core status fields, key names/labels.
  - Example: `artists.canonical_name`, `source_snapshots.source_name`, `reports.generated_at`.
- Nullable:
  - Source-dependent attributes that may be absent (URLs, counts, dates, IDs from unsupported sources).
  - Example: `events.event_datetime`, `external_profiles.external_id`, `social_metrics.play_count`.
- Partial-failure support:
  - `source_snapshots.snapshot_status`, `source_errors`, and nullable detail fields avoid dropping incomplete runs.

## Schema File
- Full DDL is in `sql/bandbrief_schema.sql`.
