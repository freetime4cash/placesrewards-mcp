import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { spawn } from "node:child_process";

const AGENT_ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const LARAVEL_ROOT = process.env.PLACESREWARDS_LARAVEL_ROOT ?? "/home/placevle/app.placesrewards.com";
const NODE_BIN = "/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node";
const REQUEST_DIR = path.join(AGENT_ROOT, "requests", "campaigns");
const RESULT_DIR = path.join(AGENT_ROOT, "results", "campaigns");
const DATA_DIR = path.join(AGENT_ROOT, "data");
const REPORT_DIR = path.join(DATA_DIR, "reports");
const PROCESSED_FILE = path.join(DATA_DIR, "github-campaign-processed.json");
const CAMPAIGN_CONTROL = path.join(AGENT_ROOT, "scripts", "campaign-control.mjs");

await fs.mkdir(REQUEST_DIR, { recursive: true });
await fs.mkdir(RESULT_DIR, { recursive: true });
await fs.mkdir(DATA_DIR, { recursive: true });

function run(command, args, cwd = AGENT_ROOT) {
  return new Promise((resolve) => {
    const child = spawn(command, args, { cwd, env: process.env, shell: false });
    let stdout = "";
    let stderr = "";
    child.stdout?.on("data", d => stdout += d.toString());
    child.stderr?.on("data", d => stderr += d.toString());
    child.on("error", error => resolve({ exitCode: -1, stdout, stderr: `${stderr}\n${error.message}`.trim() }));
    child.on("close", code => resolve({ exitCode: code ?? -1, stdout, stderr }));
  });
}

async function readProcessed() {
  try { return JSON.parse(await fs.readFile(PROCESSED_FILE, "utf8")); }
  catch { return {}; }
}

async function writeProcessed(value) {
  await fs.writeFile(PROCESSED_FILE, JSON.stringify(value, null, 2), "utf8");
}

function validRequest(req, filename) {
  const errors = [];
  if (!req || typeof req !== "object") errors.push("Request must be a JSON object.");
  if (typeof req.requestId !== "string" || !req.requestId.trim()) errors.push("requestId is required.");
  if (req.approved !== true) errors.push("approved must be true.");
  if (typeof req.campaignName !== "string" || !req.campaignName.trim()) errors.push("campaignName is required.");
  const fileBase = path.basename(filename, ".json");
  if (req?.requestId && fileBase !== req.requestId) errors.push("Filename must equal requestId.json.");
  if (["report_snapshot", "capability_snapshot", "route_snapshot", "artisan_363_demo"].includes(req.requestType)) return errors;
  if (!Array.isArray(req.steps) || req.steps.length === 0) errors.push("At least one campaign step is required.");
  return errors;
}

async function latestMatchingReport(prefix) {
  const files = await fs.readdir(REPORT_DIR);
  const matches = files.filter(name => name.startsWith(prefix) && name.endsWith(".json")).sort().reverse();
  if (!matches.length) throw new Error(`No report matching ${prefix}*.json`);
  const file = path.join(REPORT_DIR, matches[0]);
  return { file, data: JSON.parse(await fs.readFile(file, "utf8")) };
}

const processed = await readProcessed();
const files = (await fs.readdir(REQUEST_DIR)).filter(name => name.endsWith(".json")).sort();
const summary = { scanned: files.length, executed: 0, skipped: 0, failed: 0, results: [] };

for (const filename of files) {
  const full = path.join(REQUEST_DIR, filename);
  let req;
  try { req = JSON.parse(await fs.readFile(full, "utf8")); }
  catch (error) {
    summary.failed += 1;
    summary.results.push({ file: filename, status: "invalid_json", error: error instanceof Error ? error.message : String(error) });
    continue;
  }

  const requestId = String(req.requestId ?? path.basename(filename, ".json"));
  if (processed[requestId]?.status === "completed") { summary.skipped += 1; continue; }

  const errors = validRequest(req, filename);
  if (errors.length) {
    const result = { requestId, campaignName: req.campaignName ?? null, status: "blocked", processedAt: new Date().toISOString(), errors };
    await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
    processed[requestId] = { status: "blocked", processedAt: result.processedAt };
    summary.failed += 1;
    continue;
  }

  if (req.requestType === "artisan_363_demo") {
    const commandName = "placesrewards:install-363-demo";
    const listRun = await run("php", ["artisan", "list", "--raw"], LARAVEL_ROOT);
    const commandExists = listRun.exitCode === 0 && listRun.stdout.includes(commandName);
    const stages = [];
    let status = "failed";

    if (commandExists) {
      const inspect = await run("php", ["artisan", commandName, "--inspect"], LARAVEL_ROOT);
      stages.push({ stage: "inspect", ...inspect });

      if (inspect.exitCode === 0) {
        const dryRun = await run("php", ["artisan", commandName, "--dry-run"], LARAVEL_ROOT);
        stages.push({ stage: "dry-run", ...dryRun });

        if (dryRun.exitCode === 0) {
          const install = await run("php", ["artisan", commandName], LARAVEL_ROOT);
          stages.push({ stage: "install", ...install });

          if (install.exitCode === 0) {
            const verify = await run("php", ["artisan", commandName, "--inspect"], LARAVEL_ROOT);
            stages.push({ stage: "verify", ...verify });
            status = verify.exitCode === 0 ? "completed" : "failed";
          }
        }
      }
    } else {
      stages.push({ stage: "command-check", exitCode: listRun.exitCode, stdout: listRun.stdout, stderr: listRun.stderr, error: `${commandName} is not registered` });
    }

    const result = {
      requestId,
      campaignName: req.campaignName,
      status,
      requestType: "artisan_363_demo",
      processedAt: new Date().toISOString(),
      command: commandName,
      commandExists,
      stages
    };

    await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
    processed[requestId] = { status, processedAt: result.processedAt, sha256: crypto.createHash("sha256").update(await fs.readFile(full)).digest("hex") };
    if (status === "completed") summary.executed += 1; else summary.failed += 1;
    summary.results.push({ requestId, campaignName: req.campaignName, status, requestType: "artisan_363_demo" });
    continue;
  }

  if (req.requestType === "report_snapshot") {
    let status = "completed", reportFile = null, reportData = null, error = null;
    try {
      const report = await latestMatchingReport(req.reportPrefix ?? "v0.9-write-routes-");
      reportFile = report.file;
      reportData = report.data;
    } catch (e) {
      status = "failed";
      error = e instanceof Error ? e.message : String(e);
    }
    const writes = Array.isArray(reportData) ? reportData : [];
    const result = { requestId, campaignName: req.campaignName, status, requestType: "report_snapshot", processedAt: new Date().toISOString(), reportFile, campaignWriteRoutes: writes, campaignWriteRouteCount: writes.length, error };
    await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
    processed[requestId] = { status, processedAt: result.processedAt };
    if (status === "completed") summary.executed += 1; else summary.failed += 1;
    continue;
  }

  if (req.requestType === "capability_snapshot") {
    const refresh = await run(NODE_BIN, [CAMPAIGN_CONTROL, "refresh"]);
    let capabilities = null;
    try { capabilities = JSON.parse(await fs.readFile(path.join(DATA_DIR, "campaign-capabilities.json"), "utf8")); } catch {}
    const status = refresh.exitCode === 0 ? "completed" : "failed";
    const result = { requestId, campaignName: req.campaignName, status, requestType: "capability_snapshot", processedAt: new Date().toISOString(), capabilities, error: status === "failed" ? (refresh.stderr || refresh.stdout) : null };
    await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
    processed[requestId] = { status, processedAt: result.processedAt };
    if (status === "completed") summary.executed += 1; else summary.failed += 1;
    continue;
  }

  const refresh = await run(NODE_BIN, [CAMPAIGN_CONTROL, "refresh"]);
  if (refresh.exitCode !== 0) {
    const result = { requestId, campaignName: req.campaignName, status: "failed", processedAt: new Date().toISOString(), stage: "capability_refresh", error: refresh.stderr || refresh.stdout };
    await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
    processed[requestId] = { status: "failed", processedAt: result.processedAt };
    summary.failed += 1;
    continue;
  }

  const execution = await run(NODE_BIN, [CAMPAIGN_CONTROL, "execute", full]);
  let executionPayload;
  try { executionPayload = JSON.parse(execution.stdout); }
  catch { executionPayload = { stdout: execution.stdout, stderr: execution.stderr }; }
  const steps = executionPayload?.audit?.steps ?? [];
  const failed = execution.exitCode !== 0 || steps.some(step => ["failed", "blocked"].includes(step?.status));
  const status = failed ? "failed" : "completed";
  const result = { requestId, campaignName: req.campaignName, status, processedAt: new Date().toISOString(), execution: executionPayload, stderr: execution.stderr || null };
  await fs.writeFile(path.join(RESULT_DIR, `${requestId}.json`), JSON.stringify(result, null, 2), "utf8");
  processed[requestId] = { status, processedAt: result.processedAt };
  if (status === "completed") summary.executed += 1; else summary.failed += 1;
}

await writeProcessed(processed);
console.log(JSON.stringify(summary, null, 2));
