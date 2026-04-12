import { LARC } from "../larc.js";

class BandReport extends HTMLElement {
  connectedCallback() {
    this.renderIdle();

    this.offLoading = LARC.on("bb:report:loading", ({ detail }) => {
      this.renderLoading(detail?.query || "");
    });

    this.offLoaded = LARC.on("bb:report:loaded", ({ detail }) => {
      this.renderReport(detail?.report || null, detail?.meta || {});
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

  renderReport(reportEnvelope, meta) {
    const report = reportEnvelope?.report || reportEnvelope;

    if (!report || typeof report !== "object") {
      this.renderError("Report payload is empty");
      return;
    }

    const sections = Array.isArray(report.sections) ? report.sections : [];
    const sourceStatus = meta?.source_status || {};

    const sectionHtml = sections
      .map((section) => {
        const title = this.escape(String(section.name || "Untitled"));
        const json = this.escape(JSON.stringify(section.content || {}, null, 2));

        return `
          <article class="report-section">
            <h3>${title}</h3>
            <pre>${json}</pre>
          </article>
        `;
      })
      .join("");

    this.innerHTML = `
      <div class="report-head">
        <h2>${this.escape(String(report.normalized_profile?.canonical_name || "BandBrief Report"))}</h2>
        <span class="badge ${report.missing_data?.length ? "status-partial" : "status-ok"}">
          ${report.missing_data?.length ? "partial data" : "full data"}
        </span>
      </div>

      <p class="muted">Generated at ${this.escape(String(report.generated_at || "unknown"))}</p>
      <p><strong>Booking Take:</strong> ${this.escape(String(report.booking_take || ""))}</p>

      ${sourceStatus && Object.keys(sourceStatus).length ? `<pre>${this.escape(JSON.stringify(sourceStatus, null, 2))}</pre>` : ""}

      <div class="sections-grid">${sectionHtml}</div>

      <style>
        .report-head { display:flex; gap:0.6rem; align-items:center; justify-content:space-between; }
        pre {
          background: #f8f7f4;
          border: 1px solid #ddd6c9;
          border-radius: 8px;
          padding: 0.75rem;
          overflow: auto;
          font-size: 0.83rem;
        }
        .sections-grid {
          display: grid;
          gap: 0.75rem;
          margin-top: 0.75rem;
        }
      </style>
    `;
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
