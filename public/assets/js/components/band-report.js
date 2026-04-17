import { LARC } from "../larc.js";

class BandReport extends HTMLElement {
  connectedCallback() {
    this.renderIdle();

    this.offLoading = LARC.on("bb:report:loading", ({ detail }) => {
      this.renderLoading(detail?.query || "");
    });

    this.offLoaded = LARC.on("bb:report:loaded", ({ detail }) => {
      this.renderReport(detail?.report || null, detail?.meta || {}, detail?.envelope || null);
    });

    this.offError = LARC.on("bb:report:error", ({ detail }) => {
      this.renderError(detail?.message || "Unknown error");
    });
  }

  disconnectedCallback() {
    this.offLoading?.();
    this.offLoaded?.();
    this.offError?.();
  }

  renderIdle() {
    this.innerHTML = `<p class="muted">Submit an artist search to generate a BandBrief report.</p>`;
  }

  renderLoading(query) {
    this.innerHTML = `<p class="muted">Loading report for <strong>${this.escape(query)}</strong>...</p>`;
  }

  renderError(message) {
    this.innerHTML = `<p class="badge status-error">${this.escape(message)}</p>`;
  }

  renderReport(reportEnvelope, meta, envelope) {
    const report = reportEnvelope?.report || reportEnvelope;

    if (!report || typeof report !== "object") {
      this.renderError("Report payload is empty");
      return;
    }

    const summary = report.summary && typeof report.summary === "object" ? report.summary : {};
    const canonicalName = String(summary.canonical_name || report.normalized_profile?.canonical_name || "BandBrief Report");
    const overview = String(summary.overview || report.sections?.[0]?.content?.summary || "No summary available.");
    const bookingTake = String(summary.booking_take || report.booking_take || "No booking take available.");
    const missingData = Array.isArray(report.missing_data) ? report.missing_data : [];

    const sourceStatus =
      (reportEnvelope && typeof reportEnvelope === "object" && reportEnvelope.source_status) ||
      report.source_status ||
      meta?.source_status ||
      {};
    const sourceContent = report.source_content || {};

    const releases = Array.isArray(report.releases) ? report.releases : [];
    const albums = releases.filter((release) => {
      const type = String(release?.release_type || "").toLowerCase();
      return type.includes("album");
    });
    const albumPool = albums.length ? albums : releases;
    const albumRows = albumPool
      .slice(0, 24)
      .map((release) => {
        const title = this.escape(String(release.title || "Untitled release"));
        const releaseDate = this.escape(String(release.release_date || "date unknown"));
        const releaseType = this.escape(String(release.release_type || "release"));
        const source = this.escape(String(release.source || "unknown"));
        const url = String(release.url || "").trim();
        const albumMeta = `${releaseType} • ${releaseDate} • ${source}`;

        return `
          <li class="album-row">
            <div>
              <div class="album-title">${title}</div>
              <div class="muted album-meta">${albumMeta}</div>
            </div>
            ${url ? `<a class="badge" href="${this.escape(url)}" target="_blank" rel="noreferrer">Open</a>` : ""}
          </li>
        `;
      })
      .join("");

    const hiddenAlbumCount = Math.max(0, albumPool.length - 24);

    const breakdown = Array.isArray(report.score_breakdown) ? report.score_breakdown : [];
    const scoreRows = breakdown
      .map((row) => {
        const category = this.escape(String(row.category || "Category"));
        const value = Number(row.score || 0);
        const clamped = Math.max(0, Math.min(100, value));
        const weight = Number(row.weight || 0);
        const weighted = Number(row.weighted || 0);
        const explanation = String(row.explanation || "").trim();

        return `
          <div class="score-row">
            <div class="score-row-head">
              <span>${category}</span>
              <strong>${clamped}</strong>
            </div>
            <div class="bar"><span style="width:${clamped}%"></span></div>
            <div class="muted score-row-meta">${Math.round(weight * 100)}% weight • ${weighted.toFixed(2)} weighted points</div>
            ${explanation ? `<p class="muted score-row-note">${this.escape(explanation)}</p>` : ""}
          </div>
        `;
      })
      .join("");

    const bandScore = Number(report.bandbrief_score || 0);
    const identityConfidence = Number(report.score_confidence?.identity_confidence || summary.identity_confidence || 0);
    const overallConfidence = Number(report.score_confidence?.overall || 0);
    const coverageConfidence = Number(report.score_confidence?.coverage_confidence || 0);
    const evidenceConfidence = Number(report.score_confidence?.evidence_confidence || 0);
    const matchType = String(summary.match_type || report.identity?.match_type || "unknown");

    const warnings = missingData
      .map((source) => `<li>${this.escape(String(source))}</li>`)
      .join("");

    this.innerHTML = `
      <div class="report-head">
        <h2>${this.escape(canonicalName)}</h2>
        <span class="badge ${missingData.length ? "status-partial" : "status-ok"}">
          ${missingData.length ? `${missingData.length} missing/partial source(s)` : "full identity coverage"}
        </span>
      </div>

      <article class="report-section summary-top">
        <h3>Summary</h3>
        <p class="summary-lead">${this.escape(overview)}</p>
        <p class="summary-booking">${this.escape(bookingTake)}</p>
        <div class="summary-meta">
          <span class="badge">Match: ${this.escape(this.formatMatchType(matchType))}</span>
          <span class="badge">Generated: ${this.escape(this.formatTimestamp(report.generated_at))}</span>
        </div>
      </article>

      <article class="report-section score-summary">
        <div class="score-head">
          <div class="score-main">
            <span class="score-pill">BandBrief Score ${bandScore}</span>
            <span class="badge">Deterministic weighted model</span>
          </div>
          <div class="score-confidence">
            <span class="badge">overall ${Math.round(overallConfidence * 100)}%</span>
            <span class="badge">identity ${Math.round(identityConfidence * 100)}%</span>
            <span class="badge">coverage ${Math.round(coverageConfidence * 100)}%</span>
            <span class="badge">evidence ${Math.round(evidenceConfidence * 100)}%</span>
          </div>
        </div>
        <h4>Score Breakdown</h4>
        <div class="score-grid">${scoreRows || "<p class='muted'>No category breakdown provided.</p>"}</div>
      </article>

      <details class="report-section albums-section">
        <summary><strong>${albums.length ? "Albums" : "Releases"}</strong> (${albumPool.length})</summary>
        <ul class="albums-list">${albumRows || "<li class='muted'>No release data available.</li>"}</ul>
        ${hiddenAlbumCount ? `<p class="muted album-note">${hiddenAlbumCount} more not shown.</p>` : ""}
      </details>

      <article class="report-section">
        <h3>Source Freshness & Coverage</h3>
        <div class="source-badges">${this.renderSourceBadges(sourceStatus)}</div>
      </article>

      <article class="report-section">
        <h3>Source Highlights</h3>
        <div class="source-content-list">${this.renderSourceDigestCards(sourceContent, sourceStatus)}</div>
      </article>

      <article class="report-section ${warnings ? "warning" : ""}">
        <h3>Risks / Missing Data</h3>
        ${warnings ? `<ul class="risk-list">${warnings}</ul>` : "<p class='muted'>No material missing-data warnings.</p>"}
      </article>

      <details class="report-section raw-json">
        <summary>Raw Report JSON (debug)</summary>
        <pre>${this.escape(JSON.stringify(reportEnvelope || report || envelope || {}, null, 2))}</pre>
      </details>

      <style>
        .report-head {
          display: flex;
          gap: 0.6rem;
          align-items: center;
          justify-content: space-between;
          margin-bottom: 0.75rem;
        }
        .summary-top h3,
        .score-summary h4 {
          margin: 0 0 0.45rem;
        }
        .summary-lead {
          margin: 0 0 0.5rem;
          font-size: 1rem;
        }
        .summary-booking {
          margin: 0;
        }
        .summary-meta {
          display: flex;
          flex-wrap: wrap;
          gap: 0.45rem;
          margin-top: 0.7rem;
        }
        .score-head {
          display: grid;
          gap: 0.55rem;
          margin-bottom: 0.65rem;
        }
        .score-main,
        .score-confidence {
          display: flex;
          flex-wrap: wrap;
          gap: 0.45rem;
          align-items: center;
        }
        .score-pill {
          display: inline-block;
          border-radius: 999px;
          border: 1px solid #0f6e8b55;
          background: #d6edf4;
          padding: 0.25rem 0.55rem;
          font-weight: 700;
        }
        .score-grid {
          display: grid;
          gap: 0.7rem;
        }
        .score-row {
          border: 1px solid #ddd6c9;
          border-radius: 10px;
          padding: 0.55rem;
          background: #faf9f6;
        }
        .score-row-head {
          display: flex;
          justify-content: space-between;
          margin-bottom: 0.3rem;
          font-size: 0.92rem;
        }
        .score-row-meta,
        .score-row-note {
          margin: 0.45rem 0 0;
          font-size: 0.84rem;
        }
        .bar {
          width: 100%;
          background: #f0ede5;
          border-radius: 999px;
          overflow: hidden;
          height: 9px;
        }
        .bar span {
          display: block;
          height: 100%;
          background: linear-gradient(90deg, #0f6e8b, #2b93af);
        }
        .albums-section > summary {
          cursor: pointer;
        }
        .albums-list {
          list-style: none;
          padding: 0;
          margin: 0.75rem 0 0;
          display: grid;
          gap: 0.55rem;
        }
        .album-row {
          border: 1px solid #ddd6c9;
          border-radius: 10px;
          padding: 0.55rem;
          display: flex;
          justify-content: space-between;
          gap: 0.6rem;
          align-items: center;
          background: #f8f7f4;
        }
        .album-title {
          font-weight: 600;
        }
        .album-meta {
          font-size: 0.86rem;
        }
        .album-note {
          margin: 0.7rem 0 0;
        }
        .source-badges {
          display: flex;
          flex-wrap: wrap;
          gap: 0.45rem;
        }
        .source-badge {
          display: inline-flex;
          align-items: center;
          gap: 0.3rem;
        }
        .source-content-list {
          display: grid;
          gap: 0.65rem;
        }
        .source-content-panel {
          border: 1px solid #ddd6c9;
          border-radius: 10px;
          background: #f8f7f4;
          padding: 0.55rem;
        }
        .source-card-head {
          display: flex;
          justify-content: space-between;
          align-items: flex-start;
          gap: 0.5rem;
          margin-bottom: 0.4rem;
        }
        .source-card-title {
          margin: 0;
          font-size: 0.95rem;
        }
        .source-card-meta {
          margin: 0;
          font-size: 0.82rem;
        }
        .source-highlight-list {
          margin: 0.2rem 0 0;
          padding-left: 1rem;
          display: grid;
          gap: 0.25rem;
        }
        .source-highlight-list li {
          font-size: 0.88rem;
        }
        .source-raw {
          margin-top: 0.5rem;
        }
        .source-raw > summary {
          cursor: pointer;
          font-size: 0.86rem;
        }
        .risk-list {
          margin: 0;
          padding-left: 1.1rem;
          display: grid;
          gap: 0.3rem;
        }
        .warning {
          border-color: rgba(184, 101, 26, 0.35);
          background: rgba(184, 101, 26, 0.05);
        }
        .raw-json > summary {
          cursor: pointer;
        }
        pre {
          background: #f8f7f4;
          border: 1px solid #ddd6c9;
          border-radius: 8px;
          padding: 0.75rem;
          overflow: auto;
          font-size: 0.83rem;
        }
      </style>
    `;
  }

  renderSourceBadges(sourceStatus) {
    if (!sourceStatus || typeof sourceStatus !== "object") {
      return "<span class='muted'>No source status available.</span>";
    }

    const entries = Object.entries(sourceStatus);
    if (!entries.length) {
      return "<span class='muted'>No source status available.</span>";
    }

    return entries
      .map(([source, row]) => {
        const status = String(row?.status || "unknown");
        const role = String(row?.role || "source");
        const fetchedAt = String(row?.fetched_at || "");
        const freshness = fetchedAt ? this.freshnessLabel(fetchedAt) : "unknown freshness";
        const confidence = Number(row?.confidence || 0);
        const cls = status === "ok" ? "status-ok" : status === "partial" ? "status-partial" : "status-error";

        return `<span class="badge source-badge ${cls}">${this.escape(source)} (${this.escape(role)}) • ${Math.round(confidence * 100)}% • ${this.escape(freshness)}</span>`;
      })
      .join("");
  }

  renderSourceDigestCards(sourceContent, sourceStatus) {
    const contentMap = sourceContent && typeof sourceContent === "object" ? sourceContent : {};
    const statusMap = sourceStatus && typeof sourceStatus === "object" ? sourceStatus : {};

    const sources = Array.from(new Set([...Object.keys(statusMap), ...Object.keys(contentMap)])).sort();
    if (!sources.length) {
      return "<span class='muted'>No source content available.</span>";
    }

    return sources
      .map((source) => {
        const contentRow = contentMap[source] && typeof contentMap[source] === "object" ? contentMap[source] : {};
        const statusRow = statusMap[source] && typeof statusMap[source] === "object" ? statusMap[source] : {};

        const status = String(contentRow.status || statusRow.status || "unknown");
        const confidence = Number(contentRow.confidence ?? statusRow.confidence ?? 0);
        const method = String(contentRow.collection_method || statusRow.collection_method || "");
        const fetchedAt = String(contentRow.fetched_at || statusRow.fetched_at || "");
        const freshness = fetchedAt ? this.freshnessLabel(fetchedAt) : "unknown freshness";

        const payload =
          contentRow.content && typeof contentRow.content === "object"
            ? contentRow.content
            : contentRow.payload && typeof contentRow.payload === "object"
              ? contentRow.payload
              : {};

        const highlights = this.extractSourceHighlights(source, payload);
        const summaryParts = [`${status}`, `${Math.round(confidence * 100)}% confidence`, freshness];
        if (method) {
          summaryParts.push(method);
        }

        const highlightRows = highlights
          .slice(0, 8)
          .map((row) => `<li><strong>${this.escape(row.label)}:</strong> ${this.escape(row.value)}</li>`)
          .join("");

        return `
          <article class="source-content-panel">
            <div class="source-card-head">
              <div>
                <h4 class="source-card-title">${this.escape(this.formatLabel(source))}</h4>
                <p class="muted source-card-meta">${this.escape(summaryParts.join(" • "))}</p>
              </div>
            </div>
            ${highlightRows ? `<ul class="source-highlight-list">${highlightRows}</ul>` : "<p class='muted'>No digestible highlights extracted from this source.</p>"}
            ${
              Object.keys(payload).length
                ? `
              <details class="source-raw">
                <summary>Raw source JSON</summary>
                <pre>${this.escape(JSON.stringify(payload, null, 2))}</pre>
              </details>
            `
                : ""
            }
          </article>
        `;
      })
      .join("");
  }

  extractSourceHighlights(source, payload) {
    if (!payload || typeof payload !== "object") {
      return [];
    }

    const sourceKey = String(source || "").toLowerCase();
    const highlights = [];
    const seen = new Set();

    const add = (label, value) => {
      const display = this.humanizeValue(value);
      if (!display) {
        return;
      }
      const key = `${label}:${display}`;
      if (seen.has(key)) {
        return;
      }
      seen.add(key);
      highlights.push({ label, value: display });
    };

    if (sourceKey.includes("musicbrainz")) {
      add("Artist", this.pickFirst(payload, ["name", "artist.name"]));
      add("Type", this.pickFirst(payload, ["type", "artist.type"]));
      add("Country", this.pickFirst(payload, ["country", "artist.country", "area.name"]));
      add("Tags", this.pickFirst(payload, ["tags", "genres"]));
      add("Release Groups", this.pickFirst(payload, ["release_groups_total", "release_group_count", "release-groups"]));
    }

    if (sourceKey.includes("spotify")) {
      add("Followers", this.pickFirst(payload, ["followers", "followers.total", "artist.followers.total"]));
      add("Popularity", this.pickFirst(payload, ["popularity", "artist.popularity"]));
      add("Genres", this.pickFirst(payload, ["genres", "artist.genres"]));
      add("Catalog Size", this.pickFirst(payload, ["catalog_total", "album_count", "release_count", "total_releases"]));
    }

    if (sourceKey.includes("lastfm")) {
      add("Listeners", this.pickFirst(payload, ["listeners", "stats.listeners"]));
      add("Playcount", this.pickFirst(payload, ["playcount", "stats.playcount"]));
      add("Top Tags", this.pickFirst(payload, ["tags", "toptags", "top_tags"]));
    }

    if (sourceKey.includes("reddit")) {
      add("Mentions", this.pickFirst(payload, ["mentions_total", "posts_total", "total_posts"]));
      add("Subreddits", this.pickFirst(payload, ["subreddits_count", "subreddit_count"]));
      add("Upvotes", this.pickFirst(payload, ["total_upvotes", "upvotes_total"]));
      add("Top Post", this.pickFirst(payload, ["top_post.title", "posts.0.title"]));
    }

    if (sourceKey.includes("wikipedia")) {
      add("Title", this.pickFirst(payload, ["title", "name"]));
      add("Summary", this.pickFirst(payload, ["summary", "extract", "description"]));
      add("Article", this.pickFirst(payload, ["url", "canonical_url"]));
    }

    if (sourceKey.includes("bandcamp")) {
      add("Artist", this.pickFirst(payload, ["name", "artist"]));
      add("Location", this.pickFirst(payload, ["location"]));
      add("Genre", this.pickFirst(payload, ["genre", "genres"]));
      add("Profile", this.pickFirst(payload, ["url", "artist_url"]));
    }

    if (sourceKey.includes("official")) {
      add("Website", this.pickFirst(payload, ["url", "website", "official_website"]));
      add("Title", this.pickFirst(payload, ["title", "name"]));
      add("Description", this.pickFirst(payload, ["description", "summary"]));
    }

    if (highlights.length >= 4) {
      return highlights.slice(0, 8);
    }

    const fallbackRows = this.collectScalarRows(payload)
      .filter((row) => row.value !== "")
      .slice(0, 8);

    for (const row of fallbackRows) {
      add(this.formatLabel(row.path), row.value);
      if (highlights.length >= 8) {
        break;
      }
    }

    return highlights.slice(0, 8);
  }

  collectScalarRows(node, prefix = "", depth = 0, rows = []) {
    if (rows.length >= 24 || depth > 2 || node === null || node === undefined) {
      return rows;
    }

    if (Array.isArray(node)) {
      if (node.length === 0) {
        return rows;
      }

      if (node.every((value) => ["string", "number", "boolean"].includes(typeof value))) {
        rows.push({
          path: prefix || "value",
          value: this.humanizeValue(node),
        });
        return rows;
      }

      node.slice(0, 3).forEach((entry, index) => {
        this.collectScalarRows(entry, prefix ? `${prefix}.${index}` : `${index}`, depth + 1, rows);
      });
      return rows;
    }

    if (typeof node === "object") {
      Object.entries(node)
        .slice(0, 18)
        .forEach(([key, value]) => {
          const path = prefix ? `${prefix}.${key}` : key;
          if (value === null || value === undefined) {
            return;
          }
          if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
            rows.push({
              path,
              value: this.humanizeValue(value),
            });
            return;
          }
          this.collectScalarRows(value, path, depth + 1, rows);
        });
      return rows;
    }

    rows.push({
      path: prefix || "value",
      value: this.humanizeValue(node),
    });
    return rows;
  }

  pickFirst(payload, paths) {
    for (const path of paths) {
      const value = this.readPath(payload, path);
      if (value === undefined || value === null || value === "") {
        continue;
      }
      return value;
    }
    return undefined;
  }

  readPath(node, path) {
    if (!path) {
      return undefined;
    }

    const parts = String(path).split(".");
    let current = node;

    for (const part of parts) {
      if (current === null || current === undefined) {
        return undefined;
      }

      if (Array.isArray(current)) {
        const index = Number(part);
        if (!Number.isInteger(index)) {
          return undefined;
        }
        current = current[index];
        continue;
      }

      if (typeof current === "object" && Object.prototype.hasOwnProperty.call(current, part)) {
        current = current[part];
        continue;
      }

      return undefined;
    }

    return current;
  }

  humanizeValue(value) {
    if (value === null || value === undefined) {
      return "";
    }

    if (typeof value === "string") {
      const text = value.trim();
      if (!text) {
        return "";
      }
      return text.length > 190 ? `${text.slice(0, 187)}...` : text;
    }

    if (typeof value === "number") {
      if (!Number.isFinite(value)) {
        return "";
      }
      if (Math.abs(value) >= 1000) {
        return new Intl.NumberFormat("en-US").format(value);
      }
      return String(value);
    }

    if (typeof value === "boolean") {
      return value ? "yes" : "no";
    }

    if (Array.isArray(value)) {
      if (!value.length) {
        return "";
      }
      const scalarValues = value
        .filter((entry) => ["string", "number", "boolean"].includes(typeof entry))
        .map((entry) => this.humanizeValue(entry))
        .filter(Boolean);

      if (!scalarValues.length) {
        return `${value.length} item(s)`;
      }

      const shown = scalarValues.slice(0, 4).join(", ");
      return scalarValues.length > 4 ? `${shown} (+${scalarValues.length - 4} more)` : shown;
    }

    if (typeof value === "object") {
      const keys = Object.keys(value);
      return keys.length ? `${keys.length} field(s)` : "";
    }

    return String(value);
  }

  freshnessLabel(isoDate) {
    const ts = Date.parse(isoDate);
    if (!Number.isFinite(ts)) {
      return "unknown freshness";
    }

    const diffMs = Date.now() - ts;
    if (diffMs < 0) {
      return "fresh";
    }

    const diffMinutes = Math.floor(diffMs / 60000);
    if (diffMinutes < 2) {
      return "just now";
    }

    if (diffMinutes < 60) {
      return `${diffMinutes}m ago`;
    }

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 48) {
      return `${diffHours}h ago`;
    }

    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays}d ago`;
  }

  formatMatchType(value) {
    const text = String(value || "").trim();
    if (!text) {
      return "unknown";
    }
    return text.replaceAll("_", " ");
  }

  formatTimestamp(value) {
    const text = String(value || "").trim();
    if (!text) {
      return "unknown";
    }

    const parsed = Date.parse(text);
    if (!Number.isFinite(parsed)) {
      return text;
    }

    return new Date(parsed).toLocaleString();
  }

  formatLabel(value) {
    return String(value || "")
      .replaceAll(".", " ")
      .replaceAll("_", " ")
      .replaceAll("-", " ")
      .trim()
      .replace(/\s+/g, " ")
      .replace(/\b\w/g, (part) => part.toUpperCase());
  }

  escape(value) {
    return String(value)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }
}

customElements.define("band-report", BandReport);
