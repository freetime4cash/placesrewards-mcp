import { promises as fs } from "node:fs";
import path from "node:path";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const JOB_FILE = process.env.PLACESREWARDS_AGENT_JOB_FILE ?? path.join(DATA_DIR, "agent-jobs.json");
const LEDGER_FILE = path.join(DATA_DIR, "revenue-opportunity-ledger.json");
const OPPORTUNITY_FILE = path.join(DATA_DIR, "revenue-opportunities.json");
const QUEUE_FILE = path.join(DATA_DIR, "commercial-queue.json");

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

function findOpportunity(artifact, businessId) {
  if (!Array.isArray(artifact?.opportunities)) return null;
  return artifact.opportunities.find(item => String(item?.business?.id ?? item?.report?.businessId ?? "") === String(businessId ?? "")) ?? null;
}

function compactJob(job) {
  if (!job) return null;
  return {
    id: job.id,
    agent: job.agent,
    status: job.status,
    updatedAt: job.updatedAt ?? null,
    error: job.error ?? null,
    result: job.result ?? null
  };
}

function nextActionFor(stage) {
  switch (stage) {
    case "ready_for_outreach_draft": return "prepare_contact_specific_outreach_draft";
    case "needs_more_evidence": return "collect_or_validate_additional_evidence";
    case "specialist_failed": return "diagnose_and_requeue_failed_specialist";
    case "awaiting_specialists": return "allow_specialist_jobs_to_complete";
    default: return "none";
  }
}

await fs.mkdir(DATA_DIR, { recursive: true });
const ledger = await readJson(LEDGER_FILE, { opportunities: {} });
const jobs = await readJson(JOB_FILE, []);
const artifact = await readJson(OPPORTUNITY_FILE, { opportunities: [] });
const previousQueue = await readJson(QUEUE_FILE, { merchants: {} });
const jobsById = new Map((Array.isArray(jobs) ? jobs : []).map(job => [job.id, job]));
const merchants = {};
const now = new Date().toISOString();

for (const [key, entry] of Object.entries(ledger?.opportunities ?? {})) {
  const ids = Array.isArray(entry.lastJobIds) ? entry.lastJobIds : [];
  const routedJobs = ids.map(id => jobsById.get(id)).filter(Boolean);
  const byAgent = Object.fromEntries(routedJobs.map(job => [job.agent, job]));
  const expectedAgents = ["sales", "campaign_architect", "demo_factory"];
  const missingAgents = expectedAgents.filter(agent => !byAgent[agent]);
  const failedJobs = routedJobs.filter(job => job.status === "failed");
  const incompleteJobs = routedJobs.filter(job => !["completed", "failed"].includes(job.status));
  const opportunity = findOpportunity(artifact, entry.businessId);

  let stage = "awaiting_specialists";
  if (failedJobs.length) stage = "specialist_failed";
  else if (!missingAgents.length && !incompleteJobs.length && routedJobs.length === expectedAgents.length) {
    const qualification = byAgent.sales?.result?.qualification ?? null;
    stage = qualification === "qualified" ? "ready_for_outreach_draft" : "needs_more_evidence";
  }

  const prior = previousQueue?.merchants?.[key] ?? {};
  const stageChanged = prior.stage !== stage;
  merchants[key] = {
    key,
    businessId: entry.businessId ?? null,
    businessName: entry.businessName ?? opportunity?.business?.name ?? null,
    stage,
    firstSeenAt: prior.firstSeenAt ?? entry.firstQueuedAt ?? now,
    stageChangedAt: stageChanged ? now : (prior.stageChangedAt ?? now),
    updatedAt: now,
    score: entry.score ?? opportunity?.score ?? null,
    evidenceQuality: entry.evidenceQuality ?? opportunity?.evidenceQuality?.score ?? null,
    modeledMonthlyOpportunity: entry.modeledMonthlyOpportunity ?? opportunity?.report?.totalEstimatedMonthlyLoss ?? null,
    categories: entry.categories ?? (opportunity?.report?.leaks ?? []).map(leak => leak.category),
    nextAction: nextActionFor(stage),
    missingAgents,
    specialistJobs: Object.fromEntries(expectedAgents.map(agent => [agent, compactJob(byAgent[agent])] )),
    commercialPacket: stage === "ready_for_outreach_draft" || stage === "needs_more_evidence" ? {
      qualification: byAgent.sales?.result?.qualification ?? null,
      sales: byAgent.sales?.result ?? null,
      campaign: byAgent.campaign_architect?.result ?? null,
      demo: byAgent.demo_factory?.result ?? null,
      evidence: byAgent.sales?.result?.evidence ?? null,
      guardrails: {
        outreachSent: false,
        productionWritesPerformed: false,
        modeledRevenueIsNotGuaranteed: true
      }
    } : null
  };
}

const values = Object.values(merchants);
const summary = {
  total: values.length,
  readyForOutreachDraft: values.filter(item => item.stage === "ready_for_outreach_draft").length,
  needsMoreEvidence: values.filter(item => item.stage === "needs_more_evidence").length,
  awaitingSpecialists: values.filter(item => item.stage === "awaiting_specialists").length,
  specialistFailed: values.filter(item => item.stage === "specialist_failed").length
};

const queue = {
  schemaVersion: 1,
  generatedAt: now,
  source: "revenue-opportunity-autopilot",
  summary,
  merchants
};
await writeJsonAtomic(QUEUE_FILE, queue);
console.log(JSON.stringify({ ok: true, summary }, null, 2));
