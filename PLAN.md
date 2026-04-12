Important working rules:
- Use plain PHP (no frameworks like Laravel or Symfony)
- Use MySQL for storage
- Use vanilla HTML, CSS, and JavaScript only
- No build process
- No Node, no React, no Vue
- Keep everything simple, readable, and deployable as static PHP files
- Use prepared statements for all DB access
- Do not invent APIs that may not exist
- Clearly separate:
  - raw source data
  - normalized data
  - derived metrics
  - generated summaries
- Handle partial failures gracefully
- Favor deterministic logic over vague AI behavior

---

# 🧠 Stage 1 — Architecture & System Design

You are a senior web architect. Design a system called BandBrief.

BandBrief generates an intelligence report for a musical artist or band by aggregating data from multiple sources and producing a structured report and score.

Sources may include:
- Spotify
- Last.fm
- Wikipedia
- Reddit
- Bandcamp
- official websites
- event/show listings

Core functionality:
- Input: artist/band name
- Resolve identity across sources
- Fetch available data
- Normalize data into a consistent structure
- Compute derived metrics
- Compute a BandBrief Score
- Generate a human-readable report
- Store results for reuse

Technical constraints:
- Plain PHP (no frameworks)
- MySQL
- Vanilla JS frontend
- No build process
- Server-rendered pages or simple JSON API
- Modular file structure using includes and classes
- Must work even if some sources fail

Please provide:
1. architecture overview
2. folder structure
3. request flow (search → ingest → normalize → score → report)
4. how adapters should work in PHP
5. how to separate ingestion vs reporting
6. caching strategy
7. cron/job strategy using CLI PHP scripts
8. MVP vs phase 2 scope

Be practical and implementation-focused.

---

# 🗄️ Stage 2 — MySQL Schema

Design the MySQL schema for BandBrief.

Requirements:
- Use snake_case
- Include primary keys and foreign keys
- Include indexes
- Include created_at and updated_at
- Support:
  - multiple aliases per artist
  - multiple external profiles per artist
  - source snapshots over time
  - raw source payload storage
  - report history
  - score breakdowns
  - ingestion runs
  - source errors
  - partial data

Tables needed:
- artists
- artist_aliases
- external_profiles
- source_snapshots
- releases
- tracks
- events
- social_metrics
- community_mentions
- derived_metrics
- reports
- report_sections
- score_breakdowns
- ingestion_runs
- source_errors

Also provide:
1. explanation of each table
2. append-only vs current-state tables
3. example rows as JSON
4. indexing strategy
5. nullable vs required fields

Use real SQL, not pseudo-code.

---

# 🧱 Stage 3 — PHP Backend Scaffold

Create a PHP backend scaffold for BandBrief.

Requirements:
- No frameworks
- Plain PHP files and classes
- MySQL via PDO
- Modular structure
- Separation of concerns

Suggested structure:
- /public
- /inc
- /classes
- /adapters
- /templates
- /scripts

Please generate:
1. index.php (search page)
2. report.php (report view)
3. api.php (JSON endpoints)
4. db.php (PDO connection)
5. config.php
6. basic router logic
7. helper functions
8. error handling setup
9. example class structure:
   - Artist.php
   - Report.php
   - Scorer.php
   - Resolver.php
10. example prepared statement usage
11. instructions to run locally

Keep it clean, readable, and minimal.

---

# 🔌 Stage 4 — Source Adapters (PHP)

Implement the source adapter system in PHP.

Each adapter should:
- fetch data from a source
- normalize it into a common structure
- return metadata about confidence and freshness

Sources:
- Spotify
- Last.fm
- Wikipedia
- Reddit
- Bandcamp

Requirements:
- base adapter class or interface
- per-source adapter classes
- consistent return format
- timeout handling
- error handling
- partial results allowed
- source attribution included

Also build:
- adapter registry
- orchestration service to run adapters for a band name

Important:
- clearly label whether each adapter uses:
  - official API
  - public HTML parsing
  - search-based lookup
- do not assume private APIs exist
- use plain PHP (curl or file_get_contents)

Provide real PHP code.

---

# 🧩 Stage 5 — Artist Matching

Implement artist identity resolution in PHP.

Problem:
Different sources may represent the same band differently.

Build a system that:
- normalizes input names
- compares candidates from different sources
- scores matches
- determines:
  - exact match
  - likely match
  - ambiguous
  - no match

Use factors:
- name similarity
- aliases
- shared URLs
- release names
- genre
- location
- cross-platform consistency

Provide:
1. matching heuristics
2. scoring logic
3. PHP implementation
4. explainability output (why it matched)
5. edge case examples

Be conservative—avoid false positives.

---

# 📊 Stage 6 — Scoring Engine (PHP)

Implement the BandBrief scoring engine in PHP.

Score categories:
- Reach
- Momentum
- Engagement
- Release Activity
- Live Activity
- Community Signal
- Credibility

Inputs may include:
- Spotify listeners
- follower counts
- release recency
- show frequency
- Reddit mentions
- Last.fm stats
- Wikipedia presence
- Bandcamp presence

Provide:
1. weighted scoring model
2. normalization formulas
3. PHP implementation
4. score breakdown output
5. explanation text per category
6. handling for missing data
7. confidence score

Important:
- do not favor only large artists
- allow smaller active bands to score well
- keep it deterministic and explainable

---

# 🧾 Stage 7 — Report Generation

Implement the BandBrief report generator in PHP.

Report sections:
- Overview
- Platform Presence
- Releases
- Audience & Engagement
- Live Activity
- Community Signal
- Momentum
- Risks / Missing Data
- Booking Take
- Score Summary

Requirements:
- generate from structured data
- no hallucinated facts
- clearly state missing data
- support:
  - JSON output
  - HTML rendering
  - markdown rendering

Provide:
1. report builder class
2. section generator functions
3. deterministic summary templates
4. example JSON report
5. example HTML output
6. example markdown output

Tone:
- concise
- intelligent
- useful for promoters

---

# 🌐 Stage 8 — API (PHP)

Build API endpoints in PHP for BandBrief.

Endpoints:
- POST /api/resolve
- POST /api/report
- GET /api/report?id=
- GET /api/report/status?id=
- GET /api/artist?id=
- GET /api/artist/sources?id=
- GET /api/artist/scores?id=
- POST /api/artist/refresh
- GET /api/search?q=

Provide:
1. endpoint routing in PHP
2. request/response JSON
3. validation
4. error handling
5. pagination
6. status handling

Keep it simple and REST-like.

---

# 🎨 Stage 9 — Frontend (Vanilla JS)

Build the frontend for BandBrief using plain HTML, CSS, and vanilla JavaScript.

Pages:
1. Search page
2. Report page
3. Debug/admin page

Requirements:
- no frameworks
- no build process
- responsive
- dark professional UI
- clean layout

Include:
- HTML templates
- CSS
- JS for API calls
- loading states
- error states
- score visualization
- source badges
- simple charts if lightweight

Focus on:
- clarity
- fast load
- immediate visibility of BandBrief Score and recommendation

---

# 🧪 Stage 10 — Testing & Hardening

Add testing and production hardening to BandBrief.

Provide:
1. test plan
2. PHP test approach (simple scripts or PHPUnit optional)
3. test cases for:
   - matching
   - scoring
   - report generation
4. API tests
5. edge cases:
   - missing data
   - duplicate names
   - failed sources
   - partial reports

Also include:
- caching strategy
- cron job examples
- logging strategy
- rate limit protection
- security considerations (SQL injection, XSS, etc.)

Keep everything simple and practical.

---

# ⚡ Bonus: Suggested MVP scope

Start with:

* Spotify
* Last.fm
* Wikipedia
* Reddit
* Bandcamp

That’s enough to produce meaningful BandBriefs.

