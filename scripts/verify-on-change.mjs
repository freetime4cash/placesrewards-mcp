import { promises as fs } from "node:fs";
import path from "node:path";
import { spawn, execFileSync } from "node:child_process";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const STATE_FILE = path.join(ROOT, "data", "control-plane-verification.json");

function git(args) {
  return execFileSync("git", args, { cwd: ROOT, encoding: "utf8" }).trim();
}

async function patchTreasureHuntScratchLink() {
  const target = path.join(ROOT, "assets", "treasure-hunt-v4", "TreasureHuntDemoController.php");
  const oldUrl = "https://app.placesrewards.com/demo/scratch-win";
  const newUrl = "https://app.placesrewards.com/demo/treasure-hunt/scratch/play";
  try {
    const before = await fs.readFile(target, "utf8");
    const after = before.split(oldUrl).join(newUrl);
    if (after !== before) await fs.writeFile(target, after, "utf8");
    return { ok: after.includes(newUrl) && !after.includes(oldUrl), changed: after !== before, target };
  } catch (error) {
    return { ok: false, changed: false, target, error: error.message };
  }
}

async function readState() {
  try { return JSON.parse(await fs.readFile(STATE_FILE, "utf8")); }
  catch { return {}; }
}

function run(command, args) {
  return new Promise(resolve => {
    const child = spawn(command, args, { cwd: ROOT, env: process.env, shell: false });
    let stdout = "";
    let stderr = "";
    child.stdout?.on("data", chunk => { if (stdout.length < 100000) stdout += chunk.toString(); });
    child.stderr?.on("data", chunk => { if (stderr.length < 100000) stderr += chunk.toString(); });
    child.on("error", error => resolve({ exitCode: -1, stdout, stderr: `${stderr}\n${error.message}`.trim() }));
    child.on("close", code => resolve({ exitCode: code ?? -1, stdout, stderr }));
  });
}

await fs.mkdir(path.dirname(STATE_FILE), { recursive: true });
const scratchLinkPreflight = await patchTreasureHuntScratchLink();
if (!scratchLinkPreflight.ok) {
  console.error(JSON.stringify({ ok: false, stage: "treasure_hunt_scratch_link_preflight", ...scratchLinkPreflight }, null, 2));
  process.exit(1);
}

const head = git(["rev-parse", "HEAD"]);
const state = await readState();

if (state.lastTestedHead === head && state.status === "passed") {
  console.log(JSON.stringify({ ok: true, skipped: true, reason: "head_already_verified", head, scratchLinkPreflight }, null, 2));
  process.exit(0);
}

const startedAt = new Date().toISOString();
const result = await run("npm", ["test"]);
const completedAt = new Date().toISOString();
const passed = result.exitCode === 0;

const next = {
  schemaVersion: 1,
  lastTestedHead: head,
  status: passed ? "passed" : "failed",
  exitCode: result.exitCode,
  startedAt,
  completedAt,
  scratchLinkPreflight,
  stdout: result.stdout.slice(-50000),
  stderr: result.stderr.slice(-50000)
};
await fs.writeFile(STATE_FILE, JSON.stringify(next, null, 2) + "\n", "utf8");

console.log(JSON.stringify({ ok: passed, skipped: false, head, status: next.status, exitCode: result.exitCode, scratchLinkPreflight }, null, 2));
process.exit(passed ? 0 : 1);
