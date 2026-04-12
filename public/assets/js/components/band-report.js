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

    const summary = report.summary || {};
    const canonicalName = String(summary.canonical_name || report.normalized_profile?.canonical_name || "BandBrief Report");
    const missingData = Array.isArray(report.missing_data) ? report.missing_data : [];

    const sourceStatus =
      (reportEnvelope && typeof reportEnvelope === "object" && reportEnvelope.source_status) ||
      report.source_status ||
      meta?.source_status ||
      {};

    const releases = Array.isArray(report.releases) ? report.releases : [];
    const releaseRows = releases
      .slice(0, 12)
      .map((release) => {
        const title = this.escape(String(release.title || "Untitled release"));
        const releaseDate = this.escape(String(release.release_date || "date unknown"));
        const releaseType = this.escape(String(release.release_type || "release"));
        const source = this.escape(String(release.source || "unknown"));
        const url = String(release.url || "").trim();
        const key = this.escape(String(release.release_key || ""));

        return `
          <li class="release-row" data-release-key="${key}">
            <div>
              <div class="release-title">${title}</div>
              <div class="muted release-meta">${releaseType} • ${releaseDate} • ${source}</div>
            </div>
            <div class="release-actions">
              ${url ? `<a class="badge" href="${this.escape(url)}" target="_blank" rel="noreferrer">Open</a>` : ""}
              <button class="badge" type="button" disabled title="Release detail drawer is planned">Details soon</button>
            </div>
          </li>
        `;
      })
      .join("");

    const breakdown = Array.isArray(report.score_breakdown) ? report.score_breakdown : [];
    const scoreRows = breakdown
      .map((row) => {
        const category = this.escape(String(row.category || "Category"));
        const value = Number(row.score || 0);
        const clamped = Math.max(0, Math.min(100, value));

        return `
          <div class="score-row">
            <div class="score-row-head">
              <span>${category}</span>
              <strong>${clamped}</strong>
            </div>
            <div class="bar"><span style="width:${clamped}%"></span></div>
          </div>
        `;
      })
      .join("");

    const identityConfidence = Number(report.score_confidence?.identity_confidence || summary.identity_confidence || 0);
    const overallConfidence = Number(report.score_confidence?.overall || 0);

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

      <div class="summary-grid">
        <article class="report-section summary-card">
          <h3>Overview</h3>
          <p>${this.escape(String(summary.overview || report.sections?.[0]?.content?.summary || "No summary available."))}</p>
          <p class="muted"><strong>Match:</strong> ${this.escape(String(summary.match_type || report.identity?.match_type || "unknown"))}</p>
          <p class="muted"><strong>Generated:</strong> ${this.escape(String(report.generated_at || "unknown"))}</p>
        </article>

        <article class="report-section summary-card">
          <h3>Confidence</h3>
          <p><strong>Overall:</strong> ${Math.round(overallConfidence * 100)}%</p>
          <p><strong>Identity:</strong> ${Math.round(identityConfidence * 100)}%</p>
          <p><strong>Booking Take:</strong> ${this.escape(String(summary.booking_take || report.booking_take || "No booking take available."))}</p>
        </article>
      </div>

      <article class="report-section">
        <h3>Source Freshness & Coverage</h3>
        <div class="source-badges">${this.renderSourceBadges(sourceStatus)}</div>
      </article>

      <article class="report-section">
        <h3>Releases</h3>
        <ul class="releases-list">${releaseRows || "<li class='muted'>No release data available.</li>"}</ul>
      </article>

      <article class="report-section">
        <h3>Score Summary</h3>
        <div class="score-head">
          <span class="score-pill">BandBrief Score ${Number(report.bandbrief_score || 0)}</span>
          <span class="badge">Deterministic weighted model</span>
        </div>
        <div class="score-grid">${scoreRows || "<p class='muted'>No category breakdown provided.</p>"}</div>
      </article>

      <article class="report-section ${warnings ? "warning" : ""}">
        <h3>Risks / Missing Data</h3>
        ${warnings ? `<ul class="risk-list">${warnings}</ul>` : "<p class='muted'>No material missing-data warnings.</p>"}
      </article>

      <details class="report-section">
        <summary>Admin JSON (raw report payload)</summary>
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
        .summary-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
          gap: 0.75rem;
          margin-bottom: 0.75rem;
        }
        .summary-card h3 {
          margin: 0 0 0.35rem;
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
        .releases-list {
          list-style: none;
          padding: 0;
          margin: 0;
          display: grid;
          gap: 0.6rem;
        }
        .release-row {
          border: 1px solid #ddd6c9;
          border-radius: 10px;
          padding: 0.6rem;
          display: flex;
          justify-content: space-between;
          gap: 0.6rem;
        }
        .release-title {
          font-weight: 600;
        }
        .release-meta {
          font-size: 0.86rem;
        }
        .release-actions {
          display: inline-flex;
          align-items: center;
          gap: 0.35rem;
        }
        .score-head {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 0.5rem;
          margin-bottom: 0.65rem;
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
          gap: 0.5rem;
        }
        .score-row-head {
          display: flex;
          justify-content: space-between;
          margin-bottom: 0.2rem;
          font-size: 0.92rem;
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
