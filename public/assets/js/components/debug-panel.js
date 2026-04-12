import { LARC } from "../larc.js";

class DebugPanel extends HTMLElement {
  connectedCallback() {
    this.events = [];
    this.render();

    this.unsubscribe = LARC.onAny((event) => {
      this.events.unshift(event);
      this.events = this.events.slice(0, 20);
      this.render();
    });
  }

  disconnectedCallback() {
    this.unsubscribe?.();
  }

  render() {
    const rows = this.events
      .map((entry) => `<li><code>${this.escape(entry.event)}</code> <span>${this.escape(entry.emittedAt)}</span></li>`)
      .join("");

    this.innerHTML = `
      <details>
        <summary>Debug Event Log</summary>
        <ul>${rows || "<li class='muted'>No events yet</li>"}</ul>
      </details>
      <style>
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
        span { color: #6b7280; margin-left: 0.4rem; }
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
