import { LARC } from "../larc.js";

class BandSearch extends HTMLElement {
  connectedCallback() {
    this.render();
    this.bind();
  }

  render() {
    this.innerHTML = `
      <form class="band-search-form">
        <label for="bandbrief-input"><strong>Artist or Band</strong></label>
        <div class="controls">
          <input id="bandbrief-input" name="name" placeholder="e.g. Khruangbin" required />
          <div class="actions">
            <button type="submit" name="mode" value="default">Generate Brief</button>
            <button type="submit" name="mode" value="force" class="secondary">Force Re-run</button>
          </div>
        </div>
        <p class="muted">BandBrief runs explicit identity checks across MusicBrainz, Spotify, Last.fm, Wikipedia, and Bandcamp, then enriches with Reddit signal data.</p>
      </form>
      <style>
        .band-search-form { display: grid; gap: 0.65rem; }
        .controls { display: grid; grid-template-columns: 1fr auto; gap: 0.55rem; }
        .actions { display: inline-flex; gap: 0.5rem; }
        input {
          padding: 0.65rem 0.75rem;
          border: 1px solid #c8c1b4;
          border-radius: 10px;
          font-size: 1rem;
        }
        button {
          border: 1px solid #0f6e8b;
          background: #0f6e8b;
          color: #fff;
          border-radius: 10px;
          font-weight: 600;
          padding: 0.6rem 0.9rem;
          cursor: pointer;
        }
        button.secondary {
          border-color: #6e5f45;
          background: #fff;
          color: #6e5f45;
        }
        @media (max-width: 620px) {
          .controls { grid-template-columns: 1fr; }
          .actions { width: 100%; }
          .actions button { flex: 1; }
        }
      </style>
    `;
  }

  bind() {
    const form = this.querySelector(".band-search-form");

    form?.addEventListener("submit", (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      const query = String(formData.get("name") || "").trim();
      const submitter = event.submitter;
      const mode = submitter instanceof HTMLButtonElement ? String(submitter.value || "default") : "default";
      const force = mode === "force";

      if (!query) {
        return;
      }

      LARC.emit("bb:search:submitted", { query, force });
    });
  }
}

customElements.define("band-search", BandSearch);
