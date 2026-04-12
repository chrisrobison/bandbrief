const API_BASE = "/api.php";

async function request(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {}),
    },
    credentials: "same-origin",
    ...options,
  });

  const payload = await response.json();

  if (!response.ok || payload.ok === false) {
    const message = payload?.error?.message || `HTTP ${response.status}`;
    const code = payload?.error?.code || "request_failed";
    throw new Error(`${code}: ${message}`);
  }

  return payload;
}

export async function createReport(name, force = false) {
  return request("/reports/create", {
    method: "POST",
    body: JSON.stringify({ name, force }),
  });
}

export async function getReport(reportId) {
  return request(`/reports/view/${encodeURIComponent(String(reportId))}`, {
    method: "GET",
  });
}

export async function searchArtists(query, limit = 8) {
  const params = new URLSearchParams({ q: query, limit: String(limit) });

  return request(`/artists/search?${params.toString()}`, {
    method: "GET",
  });
}
