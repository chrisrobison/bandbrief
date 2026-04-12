import { LARC } from "../larc.js";

class ScoreCard extends HTMLElement {
  connectedCallback() {
    this.renderEmpty();

    this.offLoading = LARC.on("bb:report:loading", () => {
      this.renderLoading();
    });

    this.offLoaded = LARC.on("bb:report:loaded", ({ detail }) => {
      this.renderScore(detail?.report || null);
    });

    this.offError = LARC.on("bb:report:error", ({ detail }) => {
      this.renderError(detail?.message || "Report generation failed");
    });
  }

  disconnectedCallback() {
    this.offLoading?.();
    this.offLoaded?.();
    this.offError?.();
  }

  renderEmpty() {
    this.innerHTML = `<p class="muted">No score yet.</p>`;
  }

  renderLoading() {
    this.innerHTML = `<p class="muted">Scoring in progress...</p>`;
  }

  renderError(message) {
    this.innerHTML = `<p class="status-error badge">${this.escape(message)}</p>`;
  }

  renderScore(reportEnvelope) {
    const report = reportEnvelope?.report || reportEnvelope;

    if (!report || typeof report !== "object") {
      this.renderEmpty();
      return;
    }

    const score = Number(report.bandbrief_score || 0);
    const bookingTake = String(report.booking_take || report.summary?.booking_take || "No booking take available.");
    const overallConfidence = Number(report.score_confidence?.overall || 0);
    const identityConfidence = Number(report.score_confidence?.identity_confidence || report.summary?.identity_confidence || 0);

    const qualityClass = overallConfidence >= 0.7 ? "status-ok" : overallConfidence >= 0.45 ? "status-partial" : "status-error";

    this.innerHTML = `
      <div class="score-wrap">
        <div class="score-number">${score}</div>
        <div class="score-copy">
          <div><strong>BandBrief Score</strong></div>
          <div class="confidence-row">
            <span class="badge ${qualityClass}">overall ${Math.round(overallConfidence * 100)}%</span>
            <span class="badge">identity ${Math.round(identityConfidence * 100)}%</span>
          </div>
          <div class="muted">${this.escape(bookingTake)}</div>
        </div>
      </div>
      <style>
        .score-wrap {
          display: grid;
          grid-template-columns: auto 1fr;
          gap: 0.8rem;
          align-items: center;
        }
        .score-number {
          width: 72px;
          height: 72px;
          border-radius: 16px;
          border: 1px solid #0f6e8b66;
          background: #d6edf4;
          display: grid;
          place-items: center;
          font-size: 1.8rem;
          font-weight: 700;
          color: #0f6e8b;
        }
        .score-copy {
          display: grid;
          gap: 0.45rem;
        }
        .confidence-row {
          display: inline-flex;
          gap: 0.35rem;
          flex-wrap: wrap;
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

customElements.define("score-card", ScoreCard);
