import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { collectDiscrepancies, normalizeForComparison, fingerprint } from "../lib/discrepancy.js";

const left = {
  generated_at: "2026-09-06T20:00:00Z",
  status: "failed",
  nested: { updated_at: "2026-09-06T20:00:00Z", commandExists: false, command: "placesrewards:install-363-demo" }
};
const right = {
  generated_at: "2026-09-06T21:00:00Z",
  status: "failed",
  nested: { updated_at: "2026-09-06T21:00:00Z", commandExists: false, command: "placesrewards:install-363-demo" }
};

assert.deepEqual(normalizeForComparison(left), normalizeForComparison(right), "Timestamp-only changes must normalize away.");
assert.equal(fingerprint(left), fingerprint(right), "Timestamp-only changes must have the same semantic fingerprint.");

const issues = collectDiscrepancies(left, "fixture.json");
assert(issues.some(issue => issue.kind === "missing_capability"), "Missing commands must become discrepancies.");
assert(issues.some(issue => issue.kind === "failed_work"), "Failed work must become discrepancies.");

const changed = { ...right, status: "completed" };
assert.notEqual(fingerprint(left), fingerprint(changed), "Meaningful state transitions must change the semantic fingerprint.");

const cron = await readFile(new URL("../run-worker-cron.sh", import.meta.url), "utf8");
assert(cron.includes("scripts/autopilot.mjs"), "Cron must invoke autopilot.");
assert(cron.includes("scripts/semantic-result-diff.mjs"), "Cron must filter timestamp-only result changes.");

const autopilot = await readFile(new URL("./autopilot.mjs", import.meta.url), "utf8");
assert(autopilot.includes("autopilot-ledger.json"), "Autopilot must keep a durable deduplication ledger.");
assert(autopilot.includes("protected production writes"), "Autopilot must preserve protected-write safeguards.");

console.log(JSON.stringify({ ok: true, tests: 8, message: "Autonomous reconciliation invariants verified." }, null, 2));
