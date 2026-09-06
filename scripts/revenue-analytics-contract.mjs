import { promises as fs } from "node:fs";
import path from "node:path";
import { createPlacesRewardsApi } from "../lib/api.js";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const RESULT_DIR = path.join(ROOT, "results", "revenue");
const STATE_FILE = path.join(DATA_DIR, "revenue-contract-state.json");
const OUT_FILE = path.join(RESULT_DIR, "analytics-contract.json");
const INTERVAL_HOURS = Math.max(1, Number(process.env.REVENUE_CONTRACT_INTERVAL_HOURS ?? 24));
const SAMPLE_MAX = Math.max(1, Math.min(50, Number(process.env.REVENUE_CONTRACT_SAMPLE_MAX ?? 20)));

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

function valueType(value) {
  if (value === null) return "null";
  if (Array.isArray(value)) return "array";
  return typeof value;
}

function walkShape(value, prefix, paths) {
  if (Array.isArray(value)) {
    const key = prefix || "$";
    const entry = paths.get(`${key}|array`) ?? { path: key, type: "array", occurrences: 0 };
    entry.occurrences += 1;
    paths.set(`${key}|array`, entry);
    if (value[0] && typeof value[0] === "object") walkShape(value[0], `${prefix}[]`, paths);
    return;
  }
  if (!value || typeof value !== "object") return;
  for (const [rawKey, child] of Object.entries(value)) {
    const key = String(rawKey).replace(/[^a-zA-Z0-9_]+/g, "_");
    const current = prefix ? `${prefix}.${key}` : key;
    const type = valueType(child);
    const mapKey = `${current}|${type}`;
    const entry = paths.get(mapKey) ?? { path: current, type, occurrences: 0 };
    entry.occurrences += 1;
    paths.set(mapKey, entry);
    if (child && typeof child === "object") walkShape(child, current, paths);
  }
}

function semantic(document) {
  if (!document || typeof document !== "object") return null;
  return JSON.stringify({
    schemaVersion: document.schemaVersion,
    partnerFields: document.partnerFields,
    analyticsPaths: document.analyticsPaths,
    samplesWithErrors: document.samplesWithErrors
  });
}

await fs.mkdir(DATA_DIR, { recursive: true });
await fs.mkdir(RESULT_DIR, { recursive: true });
const priorState = await readJson(STATE_FILE, {});
const nowMs = Date.now();
const last = Date.parse(priorState.completedAt ?? "");
if (Number.isFinite(last) && nowMs - last < INTERVAL_HOURS * 3600000) {
  console.log(JSON.stringify({ ok: true, skipped: true, reason: "contract_interval_not_elapsed", intervalHours: INTERVAL_HOURS }, null, 2));
  process.exit(0);
}

const startedAt = new Date().toISOString();
try {
  const api = createPlacesRewardsApi();
  await api.health();
  const payload = await api.listPartners({ page: 1, perPage: SAMPLE_MAX });
  const partners = collectObjects(payload).slice(0, SAMPLE_MAX);
  const partnerFields = new Set();
  const analyticsPaths = new Map();
  let samplesWithErrors = 0;
  let sampled = 0;

  for (const partner of partners) {
    Object.keys(partner).forEach(key => partnerFields.add(String(key)));
    const id = partner.id ?? partner.uuid ?? partner.partner_id ?? partner.partnerId;
    if (!id) continue;
    try {
      const analytics = await api.partnerAnalytics(id);
      walkShape(analytics, "", analyticsPaths);
      sampled += 1;
    } catch {
      samplesWithErrors += 1;
    }
  }

  const completedAt = new Date().toISOString();
  const document = {
    schemaVersion: 1,
    generatedAt: completedAt,
    source: "placesrewards-agent-api",
    privacy: "schema-only; no metric values, merchant names, emails, IDs or contact data",
    partnersSampled: sampled,
    samplesWithErrors,
    partnerFields: [...partnerFields].sort(),
    analyticsPaths: [...analyticsPaths.values()].sort((a, b) => a.path.localeCompare(b.path) || a.type.localeCompare(b.type))
  };

  const previous = await readJson(OUT_FILE, null);
  const changed = semantic(previous) !== semantic(document);
  if (changed) await fs.writeFile(OUT_FILE, JSON.stringify(document, null, 2) + "\n", "utf8");
  await fs.writeFile(STATE_FILE, JSON.stringify({ status: "completed", startedAt, completedAt, intervalHours: INTERVAL_HOURS, partnersSampled: sampled, samplesWithErrors, schemaChanged: changed }, null, 2) + "\n", "utf8");
  console.log(JSON.stringify({ ok: true, skipped: false, partnersSampled: sampled, samplesWithErrors, schemaChanged: changed }, null, 2));
} catch (error) {
  const failedAt = new Date().toISOString();
  await fs.writeFile(STATE_FILE, JSON.stringify({ status: "failed", startedAt, failedAt, error: error instanceof Error ? error.message : String(error) }, null, 2) + "\n", "utf8");
  console.error(error);
  process.exit(1);
}
