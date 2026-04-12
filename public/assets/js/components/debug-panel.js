import { LARC } from "../larc.js";

class DebugPanel extends HTMLElement {
  connectedCallback() {
    this.events = [];
    this.lastLoaded = null;
    this.render();

    this.unsubscribeAny = LARC.onAny((event) => {
      this.events.unshift(event);
      this.events = this.events.slice(0, 40);
      this.render();
    });

    this.unsubscribeLoaded = LARC.on("bb:report:loaded", ({ detail }) => {
      this.lastLoaded = detail?.report || null;
      this.render();
    });
  }

  disconnectedCallback() {
    this.unsubscribeAny?.();
    this.unsubscribeLoaded?.();
  }

  render() {
    const rows = this.events
      .map((entry) => `<li><code>${this.escape(entry.event)}</code> <span>${this.escape(entry.emittedAt)}</span></li>`)
      .join("");

    const rawJson = this.lastLoaded ? this.escape(JSON.stringify(this.lastLoaded, null, 2)) : "";

    this.innerHTML = `
      <details>
        <summary>Debug Event Log</summary>
        <ul>${rows || "<li class='muted'>No events yet</li>"}</ul>
      </details>

      <details>
        <summary>Debug Raw Report</summary>
        ${rawJson ? `<pre>${rawJson}</pre>` : "<p class='muted'>No report loaded yet.</p>"}
      </details>

      <style>
        details + details {
          margin-top: 0.65rem;
        }
        ul {
          margin: 0.75rem 0 0;
          padding-left: 1.1rem;
          display: grid;
          gap: 0.35rem;
        }
        code {
          background: #f3f4f6;
          border: 1px solid #e5e7eb;
          padding: 0.1rem 0.3rem;
          border-radius: 4px;
        }
        span {
          color: #6b7280;
          margin-left: 0.4rem;
        }
        pre {
          background: #f8f7f4;
          border: 1px solid #ddd6c9;
          border-radius: 8px;
          padding: 0.75rem;
          overflow: auto;
          font-size: 0.83rem;
          margin-top: 0.65rem;
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

customElements.define("debug-panel", DebugPanel);
