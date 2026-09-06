function queryString(params = {}) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === "") continue;
    query.set(key, String(value));
  }
  const encoded = query.toString();
  return encoded ? `?${encoded}` : "";
}

export class PlacesRewardsApi {
  constructor(baseUrl, agentKey) { this.baseUrl = baseUrl.replace(/\/+$/, ""); this.agentKey = agentKey; }
  async request(path, options = {}) {
    const response = await fetch(`${this.baseUrl}${path}`, {
      ...options,
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-Agent-Key": this.agentKey, ...(options.headers ?? {}) }
    });
    const text = await response.text();
    let data;
    try { data = text ? JSON.parse(text) : null; }
    catch { throw new Error(`API returned non-JSON response (${response.status}): ${text.slice(0,500)}`); }
    if (!response.ok) throw new Error(`Places Rewards API error ${response.status}: ${JSON.stringify(data)}`);
    return data;
  }

  health() { return this.request("/health"); }
  tools() { return this.request("/tools"); }
  analyticsOverview() { return this.request("/admin/analytics/overview"); }
  listPartners({ page = 1, perPage = 100, search } = {}) {
    return this.request(`/admin/partners${queryString({ page, per_page: perPage, search })}`);
  }
  partner(id) { return this.request(`/admin/partners/${encodeURIComponent(id)}`); }
  partnerAnalytics(id) { return this.request(`/admin/analytics/partners/${encodeURIComponent(id)}`); }
  partnerClubs(id) { return this.request(`/admin/partners/${encodeURIComponent(id)}/clubs`); }
  listAdminMembers({ page = 1, perPage = 100, search } = {}) {
    return this.request(`/admin/members${queryString({ page, per_page: perPage, search })}`);
  }
}

export function createPlacesRewardsApi() {
  const baseUrl = process.env.PLACESREWARDS_API_URL ?? "https://app.placesrewards.com/api/agent/v1";
  const agentKey = process.env.PLACESREWARDS_AGENT_KEY;
  if (!agentKey) throw new Error("PLACESREWARDS_AGENT_KEY is not set.");
  return new PlacesRewardsApi(baseUrl, agentKey);
}
