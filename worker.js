import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { createRuntime, summarizeJobs } from "./lib/runtime.js";

const execFileAsync = promisify(execFile);

async function runChild(script) {
  try {
    const { stdout, stderr } = await execFileAsync(process.execPath, [script], {
      cwd: process.cwd(),
      env: process.env,
      maxBuffer: 1024 * 1024
    });
    try { return JSON.parse(stdout); }
    catch { return { ok: true, stdout: stdout.trim(), stderr: stderr.trim() || null }; }
  } catch (error) {
    return {
      ok: false,
      error: error instanceof Error ? error.message : String(error),
      stdout: typeof error?.stdout === "string" ? error.stdout.slice(-5000) : null,
      stderr: typeof error?.stderr === "string" ? error.stderr.slice(-5000) : null
    };
  }
}

const { orchestrator } = createRuntime();
const processed = await orchestrator.runUntilIdle();
const jobs = await orchestrator.listJobs();

// Revenue intelligence first inspects the live analytics schema without
// recording merchant values, then runs the read-only internal scan. Public
// evidence-backed prospect requests are separately validated into private
// opportunity state. Both sources feed the same commercial routing, queue and
// unsent-draft pipeline. Only aggregate privacy-safe status is exported.
// No merchant contact or production campaign write is performed by this worker.
const revenueContract = await runChild("scripts/revenue-analytics-contract.mjs");
const revenueScan = await runChild("scripts/revenue-live-scan.mjs");
const publicProspectIntake = await runChild("scripts/public-prospect-intake.mjs");
const revenueAutopilot = await runChild("scripts/revenue-opportunity-autopilot.mjs");
const commercialQueue = await runChild("scripts/commercial-queue.mjs");
const outreachDrafts = await runChild("scripts/outreach-draft-builder.mjs");
const commercialStatus = await runChild("scripts/commercial-status-export.mjs");

console.log(JSON.stringify({
  processed: processed.length,
  status: summarizeJobs(jobs),
  revenueContract,
  revenueScan,
  publicProspectIntake,
  revenueAutopilot,
  commercialQueue,
  outreachDrafts,
  commercialStatus
}, null, 2));
