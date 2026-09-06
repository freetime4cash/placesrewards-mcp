import { promises as fs } from "node:fs";
import path from "node:path";
import { evidenceQuality } from "../lib/revenue-evidence.js";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const REQUEST_DIR = path.join(ROOT, "requests", "prospects");
const DATA_DIR = path.join(ROOT, "data");
const OUT_FILE = path.join(DATA_DIR, "public-prospect-opportunities.json");

const ALLOWED_GAPS = new Set([
  "reputation",
  "booking_friction",
  "loyalty_gap",
  "referral_gap",
  "event_retention",
  "reactivation_signal",
  "followup_friction"
]);

const GAP_PLAYBOOK = Object.freeze({
  reputation: {
    title: "Public reputation signals suggest a trust/conversion opportunity",
    recommendedFix: "Build a measured review and customer-feedback loop that turns satisfied customers into current social proof.",
    module: "reviews / reputation"
  },
  booking_friction: {
    title: "Public booking path appears to contain avoidable conversion friction",
    recommendedFix: "Demonstrate a simpler capture, booking and follow-up flow with measurable completion rate.",
    module: "lead / booking follow-up"
  },
  loyalty_gap: {
    title: "No visible repeat-visit loyalty mechanic was found in the public customer journey",
    recommendedFix: "Demonstrate a measurable loyalty or stamp-card workflow designed to increase repeat visits.",
    module: "loyalty/stamp-cards"
  },
  referral_gap: {
    title: "No visible referral loop was found in the public customer journey",
    recommendedFix: "Demonstrate a trackable customer referral workflow with clear attribution.",
    module: "referrals"
  },
  event_retention: {
    title: "Public events or promotions appear to lack a visible post-event retention loop",
    recommendedFix: "Capture participants into a post-event rewards, return-visit and referral journey.",
    module: "campaigns / loyalty / referrals"
  },
  reactivation_signal: {
    title: "Public activity suggests a reactivation opportunity worth validating",
    recommendedFix: "Validate the dormant-customer baseline, then demonstrate a segmented win-back campaign.",
    module: "offers/campaigns"
  },
  followup_friction: {
    title: "Public lead/contact journey suggests follow-up friction worth validating",
    recommendedFix: "Demonstrate immediate follow-up, escalation and attribution without claiming unobserved lost revenue.",
    module: "follow-up automation"
  }
});

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

async function writeJsonAtomic(file, value) {
  await fs.mkdir(path.dirname(file), { recursive: true });
  const temp = `${file}.tmp`;
  await fs.writeFile(temp, JSON.stringify(value, null, 2) + "\n", "utf8");
  await fs.rename(temp, file);
}

function validPublicUrl(value) {
  try {
    const url = new URL(String(value));
    return url.protocol === "https:" || url.protocol === "http:";
  } catch { return false; }
}

function validate(req, filename) {
  const errors = [];
  const base = path.basename(filename, ".json");
  if (!req || typeof req !== "object") return ["request must be an object"];
  if (req.schemaVersion !== 1) errors.push("schemaVersion must be 1");
  if (req.requestType !== "public_prospect") errors.push("requestType must be public_prospect");
  if (typeof req.requestId !== "string" || !req.requestId.trim()) errors.push("requestId is required");
  if (req.requestId && req.requestId !== base) errors.push("filename must equal requestId.json");
  if (!req.business || typeof req.business !== "object" || typeof req.business.name !== "string" || !req.business.name.trim()) errors.push("business.name is required");
  const fit = Number(req.fitScore);
  if (!Number.isFinite(fit) || fit < 0 || fit > 100) errors.push("fitScore must be 0-100");
  if (!Array.isArray(req.evidence) || !req.evidence.length) errors.push("evidence must contain at least one item");
  for (const [index, item] of (req.evidence ?? []).entries()) {
    if (!item || typeof item !== "object") { errors.push(`evidence[${index}] must be an object`); continue; }
    if (!validPublicUrl(item.url)) errors.push(`evidence[${index}].url must be http(s)`);
    if (typeof item.observedFact !== "string" || !item.observedFact.trim()) errors.push(`evidence[${index}].observedFact is required`);
  }
  if (!Array.isArray(req.gaps) || !req.gaps.length) errors.push("gaps must contain at least one item");
  for (const [index, gap] of (req.gaps ?? []).entries()) {
    if (!ALLOWED_GAPS.has(gap?.category)) errors.push(`gaps[${index}].category is not allowed`);
    const confidence = Number(gap?.confidence);
    if (!Number.isFinite(confidence) || confidence < 0 || confidence > 1) errors.push(`gaps[${index}].confidence must be 0-1`);
    if (!Array.isArray(gap?.evidenceIndexes) || !gap.evidenceIndexes.length) errors.push(`gaps[${index}].evidenceIndexes is required`);
    for (const evidenceIndex of gap?.evidenceIndexes ?? []) {
      if (!Number.isInteger(evidenceIndex) || evidenceIndex < 0 || evidenceIndex >= (req.evidence?.length ?? 0)) errors.push(`gaps[${index}] references invalid evidence index ${evidenceIndex}`);
    }
  }
  return errors;
}

function buildOpportunity(req, filename) {
  const observedAt = req.observedAt ?? new Date().toISOString();
  const signals = req.gaps.map(gap => {
    const evidence = gap.evidenceIndexes.map(index => req.evidence[index]?.url).filter(Boolean);
    return {
      key: `public_${gap.category}`,
      value: true,
      source: evidence[0] ?? "public-web-research",
      observedAt,
      evidence: evidence.join(" | ")
    };
  });
  const quality = evidenceQuality(signals);
  const businessId = `public:${req.requestId}`;
  const leaks = req.gaps.map((gap, index) => {
    const playbook = GAP_PLAYBOOK[gap.category];
    const evidence = gap.evidenceIndexes.map(evidenceIndex => req.evidence[evidenceIndex]?.observedFact).filter(Boolean);
    return {
      id: `${businessId}:${gap.category}:${index + 1}`,
      businessId,
      category: gap.category,
      title: playbook.title,
      evidence,
      confidence: Number(gap.confidence),
      estimatedMonthlyLoss: 0,
      estimatedAnnualLoss: 0,
      recommendedFix: gap.recommendedFix ?? playbook.recommendedFix,
      placesRewardsModule: playbook.module,
      claimClass: "public-fit-hypothesis-not-merchant-validated-revenue-loss"
    };
  }).sort((a, b) => b.confidence - a.confidence);

  return {
    id: `prospect:${req.requestId}`,
    stage: "demonstrated",
    score: Number(req.fitScore),
    evidenceQuality: quality,
    source: "public-prospect-intake",
    sourceRequest: `requests/prospects/${filename}`,
    business: {
      id: businessId,
      name: req.business.name,
      city: req.business.city ?? null,
      website: req.business.website ?? null,
      category: req.business.category ?? null,
      signals
    },
    report: {
      businessId,
      businessName: req.business.name,
      generatedAt: new Date().toISOString(),
      totalEstimatedMonthlyLoss: 0,
      totalEstimatedAnnualLoss: 0,
      averageConfidence: leaks.length ? Math.round((leaks.reduce((sum, item) => sum + item.confidence, 0) / leaks.length) * 100) / 100 : 0,
      leaks,
      monetaryClaimAvailable: false
    },
    publicEvidence: req.evidence,
    recommendedEntryOffer: req.recommendedEntryOffer ?? null,
    notes: req.notes ?? null
  };
}

await fs.mkdir(REQUEST_DIR, { recursive: true });
await fs.mkdir(DATA_DIR, { recursive: true });
const files = (await fs.readdir(REQUEST_DIR)).filter(name => name.endsWith(".json")).sort();
const opportunities = [];
const rejected = [];

for (const filename of files) {
  const req = await readJson(path.join(REQUEST_DIR, filename), null);
  const errors = validate(req, filename);
  if (errors.length) {
    rejected.push({ filename, errors });
    continue;
  }
  opportunities.push(buildOpportunity(req, filename));
}

opportunities.sort((a, b) => b.score - a.score || b.evidenceQuality.score - a.evidenceQuality.score);
const output = {
  schemaVersion: 1,
  generatedAt: new Date().toISOString(),
  source: "requests/prospects",
  opportunityCount: opportunities.length,
  rejectedCount: rejected.length,
  opportunities,
  rejected
};
await writeJsonAtomic(OUT_FILE, output);
console.log(JSON.stringify({ ok: true, opportunityCount: opportunities.length, rejectedCount: rejected.length }, null, 2));
