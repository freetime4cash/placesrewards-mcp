import { promises as fs } from "node:fs";
import path from "node:path";
import { createRuntime, summarizeJobs } from "../lib/runtime.js";
import { collectDiscrepancies } from "../lib/discrepancy.js";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const RESULT_DIR = path.join(ROOT, "results", "campaigns");
const DATA_DIR = path.join(ROOT, "data");
const STATE_FILE = path.join(DATA_DIR, "autopilot-ledger.json");
const MAX_NEW_OBJECTIVES_PER_RUN = Number(process.env.AUTOPILOT_MAX_NEW_OBJECTIVES ?? 8);

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

async function readState() {
  const state = await readJson(STATE_FILE, null);
  return state && typeof state === "object" ? state : { version: 1, issues: {} };
}

await fs.mkdir(DATA_DIR, { recursive: true });
await fs.mkdir(RESULT_DIR, { recursive: true });

const files = (await fs.readdir(RESULT_DIR)).filter(name => name.endsWith(".json")).sort();
const current = new Map();

for (const name of files) {
  const document = await readJson(path.join(RESULT_DIR, name), null);
  if (!document) continue;
  for (const issue of collectDiscrepancies(document, `results/campaigns/${name}`)) current.set(issue.id, issue);
}

const state = await readState();
state.version = 1;
state.issues ??= {};
const now = new Date().toISOString();
const newIssues = [];

for (const [id, issue] of current) {
  const previous = state.issues[id];
  if (!previous || previous.resolvedAt) newIssues.push(issue);
  state.issues[id] = {
    ...previous,
    id,
    source: issue.source,
    kind: issue.kind,
    severity: issue.severity,
    summary: issue.summary,
    firstSeenAt: previous?.firstSeenAt ?? now,
    lastSeenAt: now,
    resolvedAt: null
  };
}

for (const [id, previous] of Object.entries(state.issues)) {
  if (!current.has(id) && !previous.resolvedAt) state.issues[id] = { ...previous, resolvedAt: now, lastSeenAt: previous.lastSeenAt ?? now };
}

const { orchestrator } = createRuntime();
const enqueued = [];

for (const issue of newIssues.slice(0, MAX_NEW_OBJECTIVES_PER_RUN)) {
  const objective = [
    `Reconcile an automatically detected Places Rewards discrepancy from ${issue.source}.`,
    `Issue: ${issue.summary}.`,
    "Inspect the live Laravel application, Agent API, control-plane code and relevant campaign state.",
    "Determine whether this is configuration drift, missing capability, stale request, data mismatch or code defect.",
    "Prepare the smallest reversible repair, validate it non-destructively, and do not execute protected production writes without the existing approval safeguard.",
    "Prefer a durable fix that prevents the same discrepancy from recurring."
  ].join(" ");

  const job = await orchestrator.enqueue({
    agent: "command",
    objective,
    input: { source: "autopilot", discrepancy: issue },
    priority: issue.severity === "high" ? 100 : 80,
    autonomyLevel: 2,
    maxAttempts: 3
  });
  enqueued.push(job.id);
  state.issues[issue.id].lastQueuedJobId = job.id;
  state.issues[issue.id].lastQueuedAt = now;
}

state.lastRunAt = now;
state.currentIssueCount = current.size;
state.newIssueCount = newIssues.length;
state.enqueuedThisRun = enqueued.length;
await fs.writeFile(STATE_FILE, JSON.stringify(state, null, 2), "utf8");

const processed = enqueued.length ? await orchestrator.runUntilIdle(100) : [];
const jobs = await orchestrator.listJobs();
console.log(JSON.stringify({
  ok: true,
  scannedFiles: files.length,
  currentIssues: current.size,
  newIssues: newIssues.length,
  enqueued,
  processed: processed.length,
  jobStatus: summarizeJobs(jobs)
}, null, 2));
