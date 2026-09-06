import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { gunzipSync } from "node:zlib";
import { createRuntime } from "../lib/runtime.js";
import { isAllowedRelativePath } from "../lib/code-tools.js";
import "./reassemble-artifacts.mjs";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const REQUEST_DIR = path.join(ROOT, "requests", "repairs");
const RESULT_DIR = path.join(ROOT, "results", "repairs");
const DATA_DIR = path.join(ROOT, "data");
const STATE_FILE = path.join(DATA_DIR, "github-repair-processed.json");
const ARTIFACT_PREFIX = "artifacts/laravel/";
const ARTIFACT_ENCODINGS = new Set(["utf8", "gzip-base64"]);

await fs.mkdir(REQUEST_DIR, { recursive: true });
await fs.mkdir(RESULT_DIR, { recursive: true });
await fs.mkdir(DATA_DIR, { recursive: true });

const sha256 = value => crypto.createHash("sha256").update(value).digest("hex");

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

async function writeJson(file, value) {
  await fs.writeFile(file, JSON.stringify(value, null, 2) + "\n", "utf8");
}

function safeArtifactPath(relative) {
  const normalized = String(relative ?? "").replace(/\\/g, "/").replace(/^\/+/, "");
  if (!normalized.startsWith(ARTIFACT_PREFIX) || normalized.includes("..")) {
    throw new Error(`Artifact path is outside ${ARTIFACT_PREFIX}: ${relative}`);
  }
  const absolute = path.resolve(ROOT, normalized);
  const allowedRoot = path.resolve(ROOT, ARTIFACT_PREFIX);
  if (!absolute.startsWith(`${allowedRoot}${path.sep}`)) throw new Error(`Artifact path escapes allowed root: ${relative}`);
  return { normalized, absolute };
}

function validateRequest(req, filename) {
  const errors = [];
  const fileBase = path.basename(filename, ".json");
  if (!req || typeof req !== "object") return ["Request must be a JSON object."];
  if (req.schemaVersion !== 1) errors.push("schemaVersion must be 1.");
  if (req.requestType !== "patch_job") errors.push("requestType must be patch_job.");
  if (typeof req.requestId !== "string" || !req.requestId.trim()) errors.push("requestId is required.");
  if (req.requestId && req.requestId !== fileBase) errors.push("Filename must equal requestId.json.");
  if (typeof req.objective !== "string" || !req.objective.trim()) errors.push("objective is required.");
  if (!Array.isArray(req.files) || req.files.length < 1 || req.files.length > 10) errors.push("files must contain 1-10 entries.");
  for (const [index, file] of (req.files ?? []).entries()) {
    if (!file || typeof file !== "object") { errors.push(`files[${index}] must be an object.`); continue; }
    if (typeof file.artifactPath !== "string" || !file.artifactPath.startsWith(ARTIFACT_PREFIX)) errors.push(`files[${index}].artifactPath must be under ${ARTIFACT_PREFIX}.`);
    if (!ARTIFACT_ENCODINGS.has(file.artifactEncoding ?? "utf8")) errors.push(`files[${index}].artifactEncoding must be utf8 or gzip-base64.`);
    if (typeof file.targetPath !== "string" || !isAllowedRelativePath(file.targetPath)) errors.push(`files[${index}].targetPath is not an allowed Laravel path.`);
    if (!/^[a-f0-9]{64}$/i.test(String(file.proposedSha256 ?? ""))) errors.push(`files[${index}].proposedSha256 must be SHA-256.`);
    if (file.expectedCurrentSha256 !== null && file.expectedCurrentSha256 !== undefined && !/^[a-f0-9]{64}$/i.test(String(file.expectedCurrentSha256))) errors.push(`files[${index}].expectedCurrentSha256 must be null or SHA-256.`);
  }
  if (req.approval?.approved === true) {
    if (req.approval.scope !== "protected_write") errors.push("Approved requests must have approval.scope=protected_write.");
    if (!Array.isArray(req.approval.files)) errors.push("Approved requests must bind approval.files.");
  }
  return errors;
}

function approvalMatches(req, preparedFiles) {
  if (req.approval?.approved !== true || req.approval.scope !== "protected_write") return false;
  const approved = Array.isArray(req.approval.files) ? req.approval.files : [];
  const expected = preparedFiles.map(file => ({
    targetPath: file.path,
    proposedSha256: file.proposedSha256,
    originalSha256: file.originalSha256 ?? null
  })).sort((a,b) => a.targetPath.localeCompare(b.targetPath));
  const actual = approved.map(file => ({
    targetPath: file.targetPath,
    proposedSha256: file.proposedSha256,
    originalSha256: file.originalSha256 ?? null
  })).sort((a,b) => String(a.targetPath).localeCompare(String(b.targetPath)));
  return JSON.stringify(actual) === JSON.stringify(expected);
}

async function loadFiles(req) {
  const files = [];
  for (const descriptor of req.files) {
    const { absolute } = safeArtifactPath(descriptor.artifactPath);
    const encoded = await fs.readFile(absolute, "utf8");
    const encoding = descriptor.artifactEncoding ?? "utf8";
    let content;
    if (encoding === "gzip-base64") {
      try { content = gunzipSync(Buffer.from(encoded.replace(/\s+/g, ""), "base64")).toString("utf8"); }
      catch (error) { throw new Error(`Could not decode gzip-base64 artifact ${descriptor.artifactPath}: ${error instanceof Error ? error.message : String(error)}`); }
    } else {
      content = encoded;
    }
    const proposedSha256 = sha256(content);
    if (proposedSha256 !== descriptor.proposedSha256) {
      throw new Error(`Artifact hash mismatch for ${descriptor.artifactPath}: expected ${descriptor.proposedSha256}, got ${proposedSha256}`);
    }
    files.push({
      path: descriptor.targetPath,
      content,
      proposedSha256,
      expectedCurrentSha256: descriptor.expectedCurrentSha256 ?? null
    });
  }
  return files;
}

async function runTarget(orchestrator, store, id, maxSteps = 100) {
  for (let i = 0; i < maxSteps; i += 1) {
    const target = await store.get(id);
    if (!target || !["queued", "running"].includes(target.status)) return target;
    const ran = await orchestrator.runNext();
    if (!ran) break;
  }
  return store.get(id);
}

function compactJob(job) {
  if (!job) return null;
  return {
    id: job.id,
    status: job.status,
    currentStep: job.currentStep ?? null,
    error: job.error ?? null,
    approval: job.approval ?? null,
    result: job.result ?? null
  };
}

const state = await readJson(STATE_FILE, { version: 1, requests: {} });
state.version = 1;
state.requests ??= {};
const files = (await fs.readdir(REQUEST_DIR)).filter(name => name.endsWith(".json")).sort();
const summary = { scanned: files.length, prepared: 0, approved: 0, completed: 0, waitingApproval: 0, failed: 0, skipped: 0, results: [] };

for (const filename of files) {
  const requestPath = path.join(REQUEST_DIR, filename);
  const raw = await fs.readFile(requestPath, "utf8");
  const requestSha256 = sha256(raw);
  let req;
  try { req = JSON.parse(raw); }
  catch (error) {
    summary.failed += 1;
    summary.results.push({ file: filename, status: "invalid_json", error: error instanceof Error ? error.message : String(error) });
    continue;
  }

  const errors = validateRequest(req, filename);
  if (errors.length) {
    const result = { requestId: req.requestId ?? path.basename(filename, ".json"), status: "blocked", errors };
    await writeJson(path.join(RESULT_DIR, `${result.requestId}.json`), result);
    summary.failed += 1;
    summary.results.push(result);
    continue;
  }

  const requestId = req.requestId;
  const previous = state.requests[requestId] ?? null;
  const { store, orchestrator } = createRuntime();

  if (previous?.status === "waiting_approval" && previous.applyJobId) {
    const applyJob = await store.get(previous.applyJobId);
    const preparedFiles = previous.preparedFiles ?? [];
    if (req.approval?.approved !== true) {
      summary.waitingApproval += 1;
      summary.skipped += 1;
      continue;
    }
    if (!approvalMatches(req, preparedFiles)) {
      const result = { requestId, status: "blocked", error: "Approval does not exactly match the prepared target path, original SHA-256 and proposed SHA-256 bindings." };
      await writeJson(path.join(RESULT_DIR, `${requestId}.json`), result);
      summary.failed += 1;
      summary.results.push(result);
      continue;
    }
    if (!applyJob || applyJob.status !== "waiting_approval") {
      const result = { requestId, status: "blocked", error: "The previously prepared apply job is no longer waiting for approval; refusing to recreate protected execution implicitly." };
      await writeJson(path.join(RESULT_DIR, `${requestId}.json`), result);
      summary.failed += 1;
      summary.results.push(result);
      continue;
    }

    await orchestrator.approveJob(previous.applyJobId, 3);
    summary.approved += 1;
    const finalJob = await runTarget(orchestrator, store, previous.applyJobId);
    const status = finalJob?.status === "completed" ? "completed" : "failed";
    const result = {
      requestId,
      requestType: "patch_job",
      status,
      requestSha256,
      preparedFiles,
      prepareJob: previous.prepareJob ?? null,
      applyJob: compactJob(finalJob),
      completedAt: new Date().toISOString()
    };
    await writeJson(path.join(RESULT_DIR, `${requestId}.json`), result);
    state.requests[requestId] = { ...previous, requestSha256, status, completedAt: result.completedAt };
    if (status === "completed") summary.completed += 1; else summary.failed += 1;
    summary.results.push({ requestId, status });
    continue;
  }

  if (previous?.status === "completed" && previous.requestSha256 === requestSha256) {
    summary.skipped += 1;
    continue;
  }

  try {
    const preparedFiles = await loadFiles(req);
    const prepareJob = await orchestrator.enqueue({
      agent: "build",
      objective: req.objective,
      priority: 120,
      autonomyLevel: 3,
      maxAttempts: 2,
      input: {
        operation: "prepare_patch",
        files: preparedFiles.map(file => ({ path: file.path, content: file.content })),
        rationale: req.rationale ?? req.objective
      }
    });
    const preparedJob = await runTarget(orchestrator, store, prepareJob.id);
    if (preparedJob?.status !== "completed" || !preparedJob?.result?.proposal?.id) {
      throw new Error(`Patch preparation did not complete: ${preparedJob?.status ?? "missing_job"}`);
    }

    const proposal = preparedJob.result.proposal;
    for (const file of preparedFiles) {
      const manifestFile = proposal.files.find(item => item.path === file.path);
      if (!manifestFile) throw new Error(`Prepared proposal omitted ${file.path}`);
      if (manifestFile.proposedSha256 !== file.proposedSha256) throw new Error(`Prepared proposal hash changed for ${file.path}`);
      if (req.files.find(item => item.targetPath === file.path)?.expectedCurrentSha256 !== undefined) {
        const expected = req.files.find(item => item.targetPath === file.path)?.expectedCurrentSha256 ?? null;
        if (manifestFile.originalSha256 !== expected) throw new Error(`Current-file precondition failed for ${file.path}: expected ${expected}, got ${manifestFile.originalSha256}`);
      }
    }

    summary.prepared += 1;
    const applyJob = await orchestrator.enqueue({
      agent: "build",
      objective: `Apply the prepared, hash-verified repair for: ${req.objective}`,
      priority: 121,
      autonomyLevel: 2,
      maxAttempts: 1,
      input: { operation: "apply_patch", proposalId: proposal.id }
    });
    const waitingJob = await runTarget(orchestrator, store, applyJob.id);
    if (waitingJob?.status !== "waiting_approval") {
      throw new Error(`Protected patch did not enter waiting_approval: ${waitingJob?.status ?? "missing_job"}`);
    }

    const preparedFileBindings = proposal.files.map(file => ({ path: file.path, proposedSha256: file.proposedSha256, originalSha256: file.originalSha256 }));
    const waitingResult = {
      requestId,
      requestType: "patch_job",
      status: "waiting_approval",
      requestSha256,
      preparedFiles: preparedFileBindings,
      prepareJob: compactJob(preparedJob),
      applyJob: compactJob(waitingJob),
      approvalRequired: {
        scope: "protected_write",
        files: preparedFileBindings.map(file => ({ targetPath: file.path, originalSha256: file.originalSha256 ?? null, proposedSha256: file.proposedSha256 }))
      }
    };
    await writeJson(path.join(RESULT_DIR, `${requestId}.json`), waitingResult);
    state.requests[requestId] = {
      requestSha256,
      status: "waiting_approval",
      proposalId: proposal.id,
      prepareJobId: preparedJob.id,
      applyJobId: waitingJob.id,
      prepareJob: compactJob(preparedJob),
      preparedFiles: preparedFileBindings,
      firstPreparedAt: new Date().toISOString()
    };

    if (req.approval?.approved === true) {
      if (!approvalMatches(req, preparedFileBindings)) throw new Error("Approval is present but does not exactly match the prepared files.");
      await orchestrator.approveJob(waitingJob.id, 3);
      summary.approved += 1;
      const finalJob = await runTarget(orchestrator, store, waitingJob.id);
      const status = finalJob?.status === "completed" ? "completed" : "failed";
      const result = { ...waitingResult, status, applyJob: compactJob(finalJob), completedAt: new Date().toISOString() };
      delete result.approvalRequired;
      await writeJson(path.join(RESULT_DIR, `${requestId}.json`), result);
      state.requests[requestId] = { ...state.requests[requestId], status, requestSha256, completedAt: result.completedAt };
      if (status === "completed") summary.completed += 1; else summary.failed += 1;
      summary.results.push({ requestId, status });
    } else {
      summary.waitingApproval += 1;
      summary.results.push({ requestId, status: "waiting_approval" });
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    const result = { requestId, requestType: "patch_job", status: "failed", requestSha256, error: message };
    await writeJson(path.join(RESULT_DIR, `${requestId}.json`), result);
    state.requests[requestId] = { requestSha256, status: "failed", error: message, failedAt: new Date().toISOString() };
    summary.failed += 1;
    summary.results.push({ requestId, status: "failed", error: message });
  }
}

state.lastRunAt = new Date().toISOString();
await writeJson(STATE_FILE, state);
console.log(JSON.stringify(summary, null, 2));
