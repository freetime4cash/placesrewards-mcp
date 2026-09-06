import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { createRuntime } from "../lib/runtime.js";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const OPPORTUNITY_FILE = path.join(DATA_DIR, "revenue-opportunities.json");
const LEDGER_FILE = path.join(DATA_DIR, "revenue-opportunity-ledger.json");
const MIN_SCORE = Number(process.env.REVENUE_AUTOPILOT_MIN_SCORE ?? 45);
const MIN_EVIDENCE = Number(process.env.REVENUE_AUTOPILOT_MIN_EVIDENCE ?? 45);
const MAX_NEW = Math.max(1, Math.min(10, Number(process.env.REVENUE_AUTOPILOT_MAX_NEW ?? 3)));
const REQUEUE_DAYS = Math.max(1, Number(process.env.REVENUE_AUTOPILOT_REQUEUE_DAYS ?? 7));

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

function stableKey(opportunity) {
  const businessId = opportunity?.business?.id ?? opportunity?.report?.businessId ?? "unknown";
  const categories = (opportunity?.report?.leaks ?? []).map(item => item.category).sort().join(",");
  return crypto.createHash("sha256").update(`${businessId}|${categories}`).digest("hex");
}

function due(entry, now) {
  if (!entry?.lastQueuedAt) return true;
  const last = Date.parse(entry.lastQueuedAt);
  if (!Number.isFinite(last)) return true;
  return now - last >= REQUEUE_DAYS * 86400000;
}

const artifact = await readJson(OPPORTUNITY_FILE, null);
if (!artifact || !Array.isArray(artifact.opportunities)) {
  console.log(JSON.stringify({ ok: true, skipped: true, reason: "no_revenue_opportunity_artifact" }, null, 2));
  process.exit(0);
}

const ledger = await readJson(LEDGER_FILE, { schemaVersion: 1, opportunities: {} });
ledger.schemaVersion = 1;
ledger.opportunities ??= {};
const nowMs = Date.now();
const now = new Date(nowMs).toISOString();

const candidates = artifact.opportunities
  .filter(opportunity => (opportunity.score ?? 0) >= MIN_SCORE)
  .filter(opportunity => (opportunity.evidenceQuality?.score ?? 0) >= MIN_EVIDENCE)
  .map(opportunity => ({ opportunity, key: stableKey(opportunity) }))
  .filter(({ key }) => due(ledger.opportunities[key], nowMs))
  .sort((a, b) => (b.opportunity.score ?? 0) - (a.opportunity.score ?? 0))
  .slice(0, MAX_NEW);

if (!candidates.length) {
  ledger.lastRunAt = now;
  ledger.lastCandidateCount = 0;
  await fs.writeFile(LEDGER_FILE, JSON.stringify(ledger, null, 2) + "\n", "utf8");
  console.log(JSON.stringify({ ok: true, skipped: true, reason: "no_new_qualified_opportunities" }, null, 2));
  process.exit(0);
}

const { orchestrator } = createRuntime();
const queued = [];

for (const { opportunity, key } of candidates) {
  const businessName = opportunity.business?.name ?? opportunity.report?.businessName ?? opportunity.report?.businessId ?? "merchant";
  const monthly = opportunity.report?.totalEstimatedMonthlyLoss ?? 0;
  const categories = (opportunity.report?.leaks ?? []).map(item => item.category);
  const sharedInput = {
    source: "revenue-opportunity-autopilot",
    opportunity,
    guardrails: {
      outreachAllowed: false,
      productionWritesAllowed: false,
      purpose: "prepare decision-ready conversion and recovery work"
    }
  };

  const sales = await orchestrator.enqueue({
    agent: "sales",
    objective: `Prepare the shortest evidence-based path to convert ${businessName} into paid Places Rewards or Revenue Engine revenue. The modeled monthly opportunity is $${monthly}. Distinguish observed evidence from estimates. Select an offer, demo, objections, close path and follow-up. Do not contact the merchant.`,
    input: sharedInput,
    priority: 96,
    autonomyLevel: 2,
    maxAttempts: 2
  });
  const campaign = await orchestrator.enqueue({
    agent: "campaign_architect",
    objective: `Design the minimum viable measurable recovery campaign for ${businessName} around these diagnosed leak categories: ${categories.join(", ") || "unknown"}. Tie every mechanic to a baseline, KPI and merchant ROI hypothesis. Do not write production data.`,
    input: sharedInput,
    priority: 94,
    autonomyLevel: 2,
    maxAttempts: 2
  });
  const demo = await orchestrator.enqueue({
    agent: "demo_factory",
    objective: `Prepare a safe personalized demo plan for ${businessName} that demonstrates the diagnosed revenue recovery opportunity without making unsupported revenue claims. Do not create or publish production demo records.`,
    input: sharedInput,
    priority: 92,
    autonomyLevel: 2,
    maxAttempts: 2
  });

  const jobIds = [sales.id, campaign.id, demo.id];
  queued.push({ key, businessName, jobIds });
  ledger.opportunities[key] = {
    businessId: opportunity.business?.id ?? opportunity.report?.businessId ?? null,
    businessName,
    categories,
    score: opportunity.score ?? null,
    evidenceQuality: opportunity.evidenceQuality?.score ?? null,
    modeledMonthlyOpportunity: monthly,
    firstQueuedAt: ledger.opportunities[key]?.firstQueuedAt ?? now,
    lastQueuedAt: now,
    lastJobIds: jobIds
  };
}

ledger.lastRunAt = now;
ledger.lastCandidateCount = candidates.length;
ledger.lastQueuedCount = queued.length;
await fs.writeFile(LEDGER_FILE, JSON.stringify(ledger, null, 2) + "\n", "utf8");

console.log(JSON.stringify({ ok: true, skipped: false, queuedOpportunityCount: queued.length, queued }, null, 2));
