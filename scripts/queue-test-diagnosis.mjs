import { promises as fs } from "node:fs";
import path from "node:path";
import { createRuntime } from "/home/placevle/placesrewards-agent-server/lib/runtime.js";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const REPORT_DIR = path.join(AGENT_ROOT, "data", "reports");

const files = (await fs.readdir(REPORT_DIR))
  .filter(name => name.startsWith("laravel-test-failure-diagnosis-") && name.endsWith(".json"))
  .sort()
  .reverse();

if (!files.length) {
  console.error("No test diagnosis report found.");
  process.exit(2);
}

const diagnosisPath = path.join(REPORT_DIR, files[0]);
const diagnosis = JSON.parse(await fs.readFile(diagnosisPath, "utf8"));

if (diagnosis.suitePassed) {
  console.log(JSON.stringify({
    queued: false,
    reason: "Test suite is already passing."
  }, null, 2));
  process.exit(0);
}

const { orchestrator } = createRuntime();

const job = await orchestrator.enqueue({
  agent: "build",
  objective:
    "Diagnose the current Laravel test-suite failure using the captured v0.6.1 failure report. " +
    "Identify the smallest safe repair. Do not change production. Prepare a repair recommendation only.",
  priority: 100,
  autonomyLevel: 2,
  input: {
    operation: "inspect",
    testFailureDiagnosis: diagnosisPath,
    referencedTestFiles: diagnosis.referencedTestFiles,
    likelyFailureLines: diagnosis.likelyFailureLines.slice(0, 50)
  }
});

console.log(JSON.stringify({
  queued: true,
  jobId: job.id,
  diagnosis: diagnosisPath,
  productionWriteAllowedAutomatically: false
}, null, 2));
