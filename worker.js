import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { createRuntime, summarizeJobs } from "./lib/runtime.js";

const execFileAsync = promisify(execFile);
const { orchestrator } = createRuntime();
const processed = await orchestrator.runUntilIdle();
const jobs = await orchestrator.listJobs();

let revenueScan = null;
try {
  const { stdout, stderr } = await execFileAsync(process.execPath, ["scripts/revenue-live-scan.mjs"], {
    cwd: process.cwd(),
    env: process.env,
    maxBuffer: 1024 * 1024
  });
  try { revenueScan = JSON.parse(stdout); }
  catch { revenueScan = { ok: true, stdout: stdout.trim(), stderr: stderr.trim() || null }; }
} catch (error) {
  revenueScan = {
    ok: false,
    error: error instanceof Error ? error.message : String(error),
    stdout: typeof error?.stdout === "string" ? error.stdout.slice(-5000) : null,
    stderr: typeof error?.stderr === "string" ? error.stderr.slice(-5000) : null
  };
}

console.log(JSON.stringify({
  processed: processed.length,
  status: summarizeJobs(jobs),
  revenueScan
}, null, 2));
