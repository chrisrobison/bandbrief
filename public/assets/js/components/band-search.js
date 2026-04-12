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
          <button type="submit">Generate Brief</button>
        </div>
        <p class="muted">BandBrief calls Spotify, Last.fm, Wikipedia, Reddit, Bandcamp, and official website discovery.</p>
      </form>
      <style>
        .band-search-form { display: grid; gap: 0.65rem; }
        .controls { display: grid; grid-template-columns: 1fr auto; gap: 0.55rem; }
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
        @media (max-width: 620px) {
          .controls { grid-template-columns: 1fr; }
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

      if (!query) {
        return;
      }

      LARC.emit("bb:search:submitted", { query });
    });
  }
}

customElements.define("band-search", BandSearch);
