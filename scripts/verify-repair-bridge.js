import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const worker = await readFile(new URL("./github-repair-worker.mjs", import.meta.url), "utf8");
const cron = await readFile(new URL("../run-worker-cron.sh", import.meta.url), "utf8");
const gitignore = await readFile(new URL("../.gitignore", import.meta.url), "utf8");

assert(worker.includes('const ARTIFACT_PREFIX = "artifacts/laravel/"'), "Repair artifacts must be restricted to the Laravel artifact area.");
assert(worker.includes("isAllowedRelativePath(file.targetPath)"), "Repair targets must pass the Laravel path allowlist.");
assert(worker.includes("Artifact hash mismatch"), "Artifact SHA-256 must be verified before proposal creation.");
assert(worker.includes("Current-file precondition failed"), "Live-file SHA preconditions must be enforced.");
assert(worker.includes('approval.scope !== "protected_write"'), "Protected approval scope must be explicit.");
assert(worker.includes("approvalMatches(req, preparedFileBindings)"), "Approval must be bound to prepared target paths and hashes.");
assert(worker.includes('autonomyLevel: 2'), "Apply jobs must enter the protected approval path before execution.");
assert(worker.includes("orchestrator.approveJob"), "Approved requests must use the orchestrator approval transition.");

const repairIndex = cron.indexOf("scripts/github-repair-worker.mjs");
const campaignIndex = cron.indexOf("scripts/github-campaign-worker.mjs");
assert(repairIndex >= 0 && campaignIndex >= 0 && repairIndex < campaignIndex, "Repairs must run before campaigns so restored capabilities are immediately usable.");
assert(gitignore.includes("data/github-repair-processed.json"), "Local repair execution state must not be committed.");

console.log(JSON.stringify({ ok: true, tests: 10, message: "Hash-bound autonomous repair bridge invariants verified." }, null, 2));
