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
    this.innerHTML = `<p class="status-error badge">${message}</p>`;
  }

  renderScore(reportEnvelope) {
    const report = reportEnvelope?.report || reportEnvelope;

    if (!report || typeof report !== "object") {
      this.renderEmpty();
      return;
    }

    const score = report.bandbrief_score ?? 0;
    const confidence = report.score_breakdown ? (report.score_breakdown.length ? "scored" : "partial") : "partial";

    this.innerHTML = `
      <div class="score-wrap">
        <div class="score-number">${score}</div>
        <div>
          <div><strong>BandBrief Score</strong></div>
          <div class="muted">${report.booking_take || "No booking take available"}</div>
          <div class="badge ${confidence === "scored" ? "status-ok" : "status-partial"}">${confidence}</div>
        </div>
      </div>
      <style>
        .score-wrap {
          display: grid;
          grid-template-columns: auto 1fr;
          gap: 0.75rem;
          align-items: center;
        }
        .score-number {
          width: 68px;
          height: 68px;
          border-radius: 14px;
          border: 1px solid #0f6e8b66;
          background: #d6edf4;
          display: grid;
          place-items: center;
          font-size: 1.7rem;
          font-weight: 700;
          color: #0f6e8b;
        }
      </style>
    `;
  }
}

customElements.define("score-card", ScoreCard);
