import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { createRuntime, summarizeJobs } from "./lib/runtime.js";

const execFileAsync = promisify(execFile);
const MAX_JOBS_PER_RUN = Math.max(
  1,
  Math.min(20, Number(process.env.PLACESREWARDS_AGENT_MAX_JOBS_PER_RUN ?? 10) || 10)
);
const CHILD_TIMEOUT_MS = Math.max(
  10_000,
  Math.min(180_000, Number(process.env.PLACESREWARDS_CHILD_TIMEOUT_MS ?? 90_000) || 90_000)
);

async function runChild(script) {
  try {
    const { stdout, stderr } = await execFileAsync(process.execPath, [script], {
      cwd: process.cwd(),
      env: process.env,
      maxBuffer: 1024 * 1024,
      timeout: CHILD_TIMEOUT_MS,
      killSignal: "SIGTERM"
    });
    try { return JSON.parse(stdout); }
    catch { return { ok: true, stdout: stdout.trim().slice(-20_000), stderr: stderr.trim().slice(-20_000) || null }; }
  } catch (error) {
    return {
      ok: false,
      error: error instanceof Error ? error.message : String(error),
      killed: Boolean(error?.killed),
      signal: error?.signal ?? null,
      stdout: typeof error?.stdout === "string" ? error.stdout.slice(-5000) : null,
      stderr: typeof error?.stderr === "string" ? error.stderr.slice(-5000) : null
    };
  }
}

const { orchestrator } = createRuntime();
const processed = await orchestrator.runUntilIdle(MAX_JOBS_PER_RUN);
const jobs = await orchestrator.listJobs();

// Revenue intelligence remains read-only here. Production campaign writes stay
// behind the dedicated campaign worker's explicit approved-request validation,
// and protected code repairs stay behind the hash-bound approval gate.
const revenueContract = await runChild("scripts/revenue-analytics-contract.mjs");
const revenueScan = await runChild("scripts/revenue-live-scan.mjs");
const publicProspectIntake = await runChild("scripts/public-prospect-intake.mjs");
const revenueAutopilot = await runChild("scripts/revenue-opportunity-autopilot.mjs");
const commercialQueue = await runChild("scripts/commercial-queue.mjs");
const outreachDrafts = await runChild("scripts/outreach-draft-builder.mjs");
const commercialStatus = await runChild("scripts/commercial-status-export.mjs");

console.log(JSON.stringify({
  processed: processed.length,
  maxJobsPerRun: MAX_JOBS_PER_RUN,
  childTimeoutMs: CHILD_TIMEOUT_MS,
  status: summarizeJobs(jobs),
  revenueContract,
  revenueScan,
  publicProspectIntake,
  revenueAutopilot,
  commercialQueue,
  outreachDrafts,
  commercialStatus
}, null, 2));
