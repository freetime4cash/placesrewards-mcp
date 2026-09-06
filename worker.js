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

// Revenue scan is read-only and internally throttled. Qualified opportunities
// are routed to executable commercial specialists, then consolidated into a
// private resumable queue. No merchant contact or production campaign write is
// performed by this worker.
const revenueScan = await runChild("scripts/revenue-live-scan.mjs");
const revenueAutopilot = await runChild("scripts/revenue-opportunity-autopilot.mjs");
const commercialQueue = await runChild("scripts/commercial-queue.mjs");

console.log(JSON.stringify({
  processed: processed.length,
  status: summarizeJobs(jobs),
  revenueScan,
  revenueAutopilot,
  commercialQueue
}, null, 2));
