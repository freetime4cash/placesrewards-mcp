import { promises as fs } from "node:fs";
import path from "node:path";
import { createPlacesRewardsApi } from "../lib/api.js";
import { runRevenueDiagnosticWorkflow, serializeRevenueOpportunity } from "../lib/revenue-opportunity.js";
import { evidenceQuality } from "../lib/revenue-evidence.js";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const STATE_FILE = path.join(DATA_DIR, "revenue-scan-state.json");
const OPPORTUNITY_FILE = path.join(DATA_DIR, "revenue-opportunities.json");
const INTERVAL_HOURS = Math.max(1, Number(process.env.REVENUE_SCAN_INTERVAL_HOURS ?? 6));
const MAX_PARTNERS = Math.max(1, Math.min(1000, Number(process.env.REVENUE_SCAN_MAX_PARTNERS ?? 250)));

const aliases = Object.freeze({
  average_ticket: ["average_ticket", "avg_ticket", "average_transaction_value", "avg_transaction_value", "average_order_value", "average_purchase_value", "average_spend"],
  missed_calls_monthly: ["missed_calls_monthly", "monthly_missed_calls"],
  call_conversion_rate: ["call_conversion_rate", "phone_conversion_rate"],
  dormant_customers: ["dormant_customers", "dormant_members", "inactive_customers", "inactive_members", "lapsed_customers", "lapsed_members"],
  reactivation_rate: ["reactivation_rate", "winback_rate", "win_back_rate"],
  repeat_purchase_gap_monthly: ["repeat_purchase_gap_monthly", "repeat_gap_monthly", "missing_repeat_transactions"],
  uncontacted_leads_monthly: ["uncontacted_leads_monthly", "unfollowed_leads_monthly", "uncontacted_leads"],
  lead_conversion_rate: ["lead_conversion_rate", "lead_close_rate"],
  no_shows_monthly: ["no_shows_monthly", "monthly_no_shows", "no_show_count"],
  no_show_recovery_rate: ["no_show_recovery_rate", "appointment_recovery_rate"]
});

const revenueKeys = ["total_revenue", "revenue", "gross_revenue", "transaction_revenue", "sales_total"];
const transactionKeys = ["total_transactions", "transaction_count", "transactions", "purchase_count", "purchases"];

const snake = value => String(value).replace(/([a-z0-9])([A-Z])/g, "$1_$2").replace(/[^a-zA-Z0-9]+/g, "_").replace(/^_+|_+$/g, "").toLowerCase();

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

function collectObjects(payload) {
  if (Array.isArray(payload)) return payload.filter(item => item && typeof item === "object");
  if (!payload || typeof payload !== "object") return [];
  for (const key of ["data", "items", "partners", "results", "records"]) {
    if (Array.isArray(payload[key])) return payload[key].filter(item => item && typeof item === "object");
    if (payload[key] && typeof payload[key] === "object") {
      const nested = collectObjects(payload[key]);
      if (nested.length) return nested;
    }
  }
  return [];
}

function flattenNumeric(node, prefix = "", out = new Map()) {
  if (Array.isArray(node)) return out;
  if (!node || typeof node !== "object") return out;
  for (const [rawKey, value] of Object.entries(node)) {
    const key = snake(rawKey);
    const full = prefix ? `${prefix}_${key}` : key;
    if (typeof value === "number" && Number.isFinite(value)) {
      out.set(full, value);
      if (!out.has(key)) out.set(key, value);
    } else if (value && typeof value === "object" && !Array.isArray(value)) flattenNumeric(value, full, out);
  }
  return out;
}

function firstMetric(flat, names) {
  for (const name of names) {
    const normalized = snake(name);
    if (flat.has(normalized)) return flat.get(normalized);
    for (const [key, value] of flat) if (key.endsWith(`_${normalized}`)) return value;
  }
  return undefined;
}

function buildBusiness(partner, analytics, observedAt) {
  const flat = flattenNumeric(analytics);
  const metrics = {};
  const signals = [];
  const source = "placesrewards-agent-api";
  const partnerId = String(partner.id ?? partner.uuid ?? partner.partner_id ?? partner.partnerId ?? "");
  const evidence = partnerId ? `/admin/analytics/partners/${partnerId}` : "/admin/analytics/partners/{id}";

  for (const [canonical, names] of Object.entries(aliases)) {
    const value = firstMetric(flat, names);
    if (typeof value === "number" && Number.isFinite(value)) {
      metrics[canonical] = value;
      signals.push({ key: canonical, value, source, observedAt, evidence });
    }
  }

  if (metrics.average_ticket === undefined) {
    const revenue = firstMetric(flat, revenueKeys);
    const transactions = firstMetric(flat, transactionKeys);
    if (typeof revenue === "number" && typeof transactions === "number" && transactions > 0 && revenue >= 0) {
      metrics.average_ticket = Math.round((revenue / transactions) * 100) / 100;
      signals.push({ key: "average_ticket", value: metrics.average_ticket, source, observedAt, evidence, derivedFrom: ["revenue", "transaction_count"] });
    }
  }

  const name = partner.name ?? partner.business_name ?? partner.businessName ?? partner.company ?? partner.title ?? `Partner ${partnerId || "unknown"}`;
  return {
    id: partnerId || snake(name) || "unknown-partner",
    name,
    source,
    observedAt,
    averageTicket: metrics.average_ticket,
    metrics,
    signals,
    diagnosticMetricCount: Object.keys(metrics).length,
    availableNumericMetricCount: flat.size
  };
}

async function listAllPartners(api) {
  const partners = [];
  for (let page = 1; partners.length < MAX_PARTNERS && page <= 20; page += 1) {
    const payload = await api.listPartners({ page, perPage: Math.min(100, MAX_PARTNERS - partners.length) });
    const batch = collectObjects(payload);
    if (!batch.length) break;
    partners.push(...batch);
    if (batch.length < 100) break;
  }
  return partners.slice(0, MAX_PARTNERS);
}

await fs.mkdir(DATA_DIR, { recursive: true });
const prior = await readJson(STATE_FILE, {});
const now = Date.now();
const last = Date.parse(prior.completedAt ?? prior.startedAt ?? "");
if (Number.isFinite(last) && prior.status === "completed" && now - last < INTERVAL_HOURS * 3600000) {
  console.log(JSON.stringify({ ok: true, skipped: true, reason: "scan_interval_not_elapsed", lastCompletedAt: prior.completedAt, intervalHours: INTERVAL_HOURS }, null, 2));
  process.exit(0);
}

const startedAt = new Date().toISOString();
await fs.writeFile(STATE_FILE, JSON.stringify({ status: "running", startedAt, intervalHours: INTERVAL_HOURS }, null, 2) + "\n", "utf8");

try {
  const api = createPlacesRewardsApi();
  await api.health();
  const partners = await listAllPartners(api);
  const opportunities = [];
  const observations = [];

  for (const partner of partners) {
    const id = partner.id ?? partner.uuid ?? partner.partner_id ?? partner.partnerId;
    if (!id) continue;
    try {
      const analytics = await api.partnerAnalytics(id);
      const observedAt = new Date().toISOString();
      const business = buildBusiness(partner, analytics, observedAt);
      const quality = evidenceQuality(business.signals);
      observations.push({
        partnerId: business.id,
        partnerName: business.name,
        diagnosticMetricCount: business.diagnosticMetricCount,
        availableNumericMetricCount: business.availableNumericMetricCount,
        evidenceQuality: quality
      });
      if (!business.averageTicket || business.diagnosticMetricCount < 2) continue;
      const opportunity = runRevenueDiagnosticWorkflow(business, { tier: "pro", source: "placesrewards-live-analytics" });
      if (!opportunity.report?.leaks?.length) continue;
      opportunities.push({
        ...serializeRevenueOpportunity(opportunity),
        evidenceQuality: quality
      });
    } catch (error) {
      observations.push({ partnerId: String(id), error: error instanceof Error ? error.message : String(error) });
    }
  }

  opportunities.sort((a, b) => (b.score ?? 0) - (a.score ?? 0));
  const completedAt = new Date().toISOString();
  const artifact = {
    schemaVersion: 1,
    generatedAt: completedAt,
    source: "placesrewards-live-agent-api",
    partnersScanned: partners.length,
    opportunitiesFound: opportunities.length,
    opportunities,
    observations
  };
  await fs.writeFile(OPPORTUNITY_FILE, JSON.stringify(artifact, null, 2) + "\n", "utf8");
  await fs.writeFile(STATE_FILE, JSON.stringify({
    status: "completed",
    startedAt,
    completedAt,
    intervalHours: INTERVAL_HOURS,
    partnersScanned: partners.length,
    opportunitiesFound: opportunities.length,
    partnersWithDiagnosticSignals: observations.filter(item => (item.diagnosticMetricCount ?? 0) > 0).length,
    errors: observations.filter(item => item.error).length
  }, null, 2) + "\n", "utf8");
  console.log(JSON.stringify({ ok: true, skipped: false, partnersScanned: partners.length, opportunitiesFound: opportunities.length }, null, 2));
} catch (error) {
  const failedAt = new Date().toISOString();
  await fs.writeFile(STATE_FILE, JSON.stringify({ status: "failed", startedAt, failedAt, error: error instanceof Error ? error.message : String(error) }, null, 2) + "\n", "utf8");
  console.error(error);
  process.exit(1);
}
