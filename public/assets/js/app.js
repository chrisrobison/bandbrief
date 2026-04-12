import { LARC } from "./larc.js";
import { createReport } from "./api.js";
import { setState } from "./state.js";

import "./components/band-search.js";
import "./components/band-report.js";
import "./components/score-card.js";
import "./components/debug-panel.js";

window.BAND_BRIEF_LARC = LARC;

LARC.on("bb:search:submitted", async ({ detail }) => {
  const query = String(detail?.query || "").trim();

  if (!query) {
    return;
  }

  setState({ loading: true, error: null, lastQuery: query });
  LARC.emit("bb:report:loading", { query });

  try {
    const envelope = await createReport(query, false);
    const data = envelope.data || {};

    setState({ loading: false, report: data, error: null });
    LARC.emit("bb:report:loaded", {
      query,
      report: data,
      meta: envelope.meta || {},
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unknown error";
    setState({ loading: false, error: message });
    LARC.emit("bb:report:error", { query, message });
  }
});
