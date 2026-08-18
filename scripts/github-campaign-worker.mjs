import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { spawn } from "node:child_process";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const NODE_BIN =
  "/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node";

const REQUEST_DIR = path.join(AGENT_ROOT, "requests", "campaigns");
const RESULT_DIR = path.join(AGENT_ROOT, "results", "campaigns");
const DATA_DIR = path.join(AGENT_ROOT, "data");
const PROCESSED_FILE = path.join(DATA_DIR, "github-campaign-processed.json");
const CAMPAIGN_CONTROL = path.join(AGENT_ROOT, "scripts", "campaign-control.mjs");

await fs.mkdir(REQUEST_DIR, { recursive: true });
await fs.mkdir(RESULT_DIR, { recursive: true });
await fs.mkdir(DATA_DIR, { recursive: true });

async function readProcessed() {
  try {
    return JSON.parse(await fs.readFile(PROCESSED_FILE, "utf8"));
  } catch {
    return {};
  }
}

async function writeProcessed(value) {
  await fs.writeFile(PROCESSED_FILE, JSON.stringify(value, null, 2), "utf8");
}

function run(command, args, cwd = AGENT_ROOT) {
  return new Promise((resolve) => {
    const child = spawn(command, args, {
      cwd,
      env: process.env,
      shell: false
    });

    let stdout = "";
    let stderr = "";

    child.stdout?.on("data", d => stdout += d.toString());
    child.stderr?.on("data", d => stderr += d.toString());

    child.on("error", error => {
      resolve({
        exitCode: -1,
        stdout,
        stderr: `${stderr}\n${error.message}`.trim()
      });
    });

    child.on("close", code => {
      resolve({
        exitCode: code ?? -1,
        stdout,
        stderr
      });
    });
  });
}

function validRequest(req, filename) {
  const errors = [];

  if (!req || typeof req !== "object") errors.push("Request must be a JSON object.");
  if (typeof req.requestId !== "string" || !req.requestId.trim()) errors.push("requestId is required.");
  if (req.approved !== true) errors.push("approved must be true.");
  if (typeof req.campaignName !== "string" || !req.campaignName.trim()) errors.push("campaignName is required.");
  if (!Array.isArray(req.steps) || req.steps.length === 0) errors.push("At least one campaign step is required.");

  if (Array.isArray(req.steps)) {
    for (const [i, step] of req.steps.entries()) {
      const method = String(step?.method ?? "").toUpperCase();
      const endpoint = String(step?.path ?? "");

      if (!["GET","POST","PUT","PATCH","DELETE"].includes(method)) {
        errors.push(`steps[${i}].method is invalid.`);
      }

      if (!endpoint.startsWith("/")) {
        errors.push(`steps[${i}].path must begin with /.`);
      }

      if (endpoint.includes("..")) {
        errors.push(`steps[${i}].path is invalid.`);
      }
    }
  }

  const fileBase = path.basename(filename, ".json");
  if (req?.requestId && fileBase !== req.requestId) {
    errors.push("Filename must equal requestId.json.");
  }

  return errors;
}

const processed = await readProcessed();
const files = (await fs.readdir(REQUEST_DIR))
  .filter(name => name.endsWith(".json"))
  .sort();

const summary = {
  scanned: files.length,
  executed: 0,
  skipped: 0,
  failed: 0,
  results: []
};

for (const filename of files) {
  const full = path.join(REQUEST_DIR, filename);

  let req;
  try {
    req = JSON.parse(await fs.readFile(full, "utf8"));
  } catch (error) {
    summary.failed += 1;
    summary.results.push({
      file: filename,
      status: "invalid_json",
      error: error instanceof Error ? error.message : String(error)
    });
    continue;
  }

  const requestId = String(req.requestId ?? path.basename(filename, ".json"));

  if (processed[requestId]?.status === "completed") {
    summary.skipped += 1;
    continue;
  }

  const errors = validRequest(req, filename);

  if (errors.length) {
    const result = {
      requestId,
      campaignName: req.campaignName ?? null,
      status: "blocked",
      processedAt: new Date().toISOString(),
      errors
    };

    await fs.writeFile(
      path.join(RESULT_DIR, `${requestId}.json`),
      JSON.stringify(result, null, 2),
      "utf8"
    );

    processed[requestId] = {
      status: "blocked",
      processedAt: result.processedAt
    };

    summary.failed += 1;
    summary.results.push(result);
    continue;
  }

  // Refresh the currently allowed live Agent API tool catalog immediately before execution.
  const refresh = await run(NODE_BIN, [CAMPAIGN_CONTROL, "refresh"]);

  if (refresh.exitCode !== 0) {
    const result = {
      requestId,
      campaignName: req.campaignName,
      status: "failed",
      processedAt: new Date().toISOString(),
      stage: "capability_refresh",
      error: refresh.stderr || refresh.stdout
    };

    await fs.writeFile(
      path.join(RESULT_DIR, `${requestId}.json`),
      JSON.stringify(result, null, 2),
      "utf8"
    );

    processed[requestId] = {
      status: "failed",
      processedAt: result.processedAt
    };

    summary.failed += 1;
    summary.results.push(result);
    continue;
  }

  const execution = await run(
    NODE_BIN,
    [CAMPAIGN_CONTROL, "execute", full]
  );

  let executionPayload = null;

  try {
    executionPayload = JSON.parse(execution.stdout);
  } catch {
    executionPayload = {
      stdout: execution.stdout,
      stderr: execution.stderr
    };
  }

  const executionSteps =
    executionPayload?.audit?.steps ??
    executionPayload?.auditFile
      ? executionPayload?.audit?.steps ?? []
      : [];

  const anyFailed =
    execution.exitCode !== 0 ||
    executionSteps.some(step =>
      ["failed","blocked"].includes(step?.status)
    );

  const result = {
    requestId,
    campaignName: req.campaignName,
    status: anyFailed ? "failed" : "completed",
    processedAt: new Date().toISOString(),
    sourceFile: filename,
    execution: executionPayload,
    stderr: execution.stderr || null
  };

  await fs.writeFile(
    path.join(RESULT_DIR, `${requestId}.json`),
    JSON.stringify(result, null, 2),
    "utf8"
  );

  processed[requestId] = {
    status: result.status,
    processedAt: result.processedAt,
    sha256: crypto
      .createHash("sha256")
      .update(await fs.readFile(full))
      .digest("hex")
  };

  if (result.status === "completed") {
    summary.executed += 1;
  } else {
    summary.failed += 1;
  }

  summary.results.push({
    requestId,
    campaignName: req.campaignName,
    status: result.status
  });
}

await writeProcessed(processed);

console.log(JSON.stringify(summary, null, 2));
