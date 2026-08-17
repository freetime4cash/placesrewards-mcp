import { promises as fs } from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const LARAVEL_ROOT =
  process.env.PLACESREWARDS_LARAVEL_ROOT ??
  "/home/placevle/app.placesrewards.com";

const REPORT_DIR = path.join(AGENT_ROOT, "data", "reports");
const BACKLOG_FILE = path.join(AGENT_ROOT, "data", "autonomous-backlog.json");

await fs.mkdir(REPORT_DIR, { recursive: true });

function run(command, args) {
  return new Promise((resolve) => {
    const child = spawn(command, args, {
      cwd: LARAVEL_ROOT,
      env: process.env,
      shell: false
    });

    let stdout = "";
    let stderr = "";

    child.stdout?.on("data", d => stdout += d.toString());
    child.stderr?.on("data", d => stderr += d.toString());

    child.on("error", error => {
      resolve({
        command: [command, ...args].join(" "),
        exitCode: -1,
        stdout,
        stderr: `${stderr}\n${error.message}`.trim()
      });
    });

    child.on("close", code => {
      resolve({
        command: [command, ...args].join(" "),
        exitCode: code ?? -1,
        stdout,
        stderr
      });
    });
  });
}

function redact(text) {
  return String(text ?? "")
    .replace(/base64:[A-Za-z0-9+/=]{20,}/g, "[REDACTED_APP_KEY]")
    .replace(/(password|secret|token|api[_-]?key)\s*[:=]\s*[^\s"'<>]+/gi, "$1=[REDACTED]");
}

const stamp = new Date().toISOString().replace(/[:.]/g, "-");
const rawReport = path.join(REPORT_DIR, `laravel-test-failure-raw-${stamp}.txt`);
const jsonReport = path.join(REPORT_DIR, `laravel-test-failure-diagnosis-${stamp}.json`);
const briefReport = path.join(REPORT_DIR, `laravel-test-repair-brief-${stamp}.txt`);

const full = await run("php", ["artisan", "test", "--stop-on-failure", "--colors=never"]);

const combined = redact(`${full.stdout}\n${full.stderr}`);
await fs.writeFile(rawReport, combined, "utf8");

const lines = combined.split(/\r?\n/);

const likelyFailures = lines.filter(line =>
  /FAIL|FAILED|Error|Exception|Expected|Failed asserting|Tests\\|tests\//i.test(line)
).slice(0, 200);

const fileMatches = new Set();

for (const line of lines) {
  for (const match of line.matchAll(/(?:tests\/[A-Za-z0-9_./-]+\.php|Tests\\[A-Za-z0-9_\\]+|[A-Za-z0-9_./-]+Test\.php)/g)) {
    fileMatches.add(match[0]);
  }
}

const failedTestFiles = [...fileMatches].slice(0, 50);

let backlog = [];
try {
  backlog = JSON.parse(await fs.readFile(BACKLOG_FILE, "utf8"));
} catch {}

const diagnosis = {
  version: "0.6.1",
  generatedAt: new Date().toISOString(),
  productionModified: false,
  command: full.command,
  exitCode: full.exitCode,
  suitePassed: full.exitCode === 0,
  likelyFailureLines: likelyFailures,
  referencedTestFiles: failedTestFiles,
  existingBacklogTop: Array.isArray(backlog) ? backlog.slice(0, 5) : [],
  rawReport
};

await fs.writeFile(jsonReport, JSON.stringify(diagnosis, null, 2), "utf8");

const brief = [
  "PLACESREWARDS LARAVEL TEST REPAIR BRIEF",
  "=======================================",
  "",
  `Generated: ${diagnosis.generatedAt}`,
  `Production modified: NO`,
  `Test command exit code: ${full.exitCode}`,
  "",
  "REFERENCED TEST FILES",
  "---------------------",
  ...(failedTestFiles.length ? failedTestFiles : ["No test-file path parsed automatically."]),
  "",
  "LIKELY FAILURE OUTPUT",
  "---------------------",
  ...(likelyFailures.length ? likelyFailures : ["No concise failure lines parsed; inspect raw report."]),
  "",
  `Raw report: ${rawReport}`,
  `JSON diagnosis: ${jsonReport}`,
  "",
  "POLICY",
  "------",
  "Diagnose first.",
  "Do not change production automatically.",
  "Any repair must be prepared as an isolated patch proposal.",
  "Production apply requires explicit approval and rollback protection."
].join("\n");

await fs.writeFile(briefReport, brief, "utf8");

console.log(JSON.stringify({
  result: full.exitCode === 0 ? "SUITE_ALREADY_PASSING" : "FAILURE_CAPTURED",
  productionModified: false,
  exitCode: full.exitCode,
  referencedTestFiles: failedTestFiles,
  likelyFailureLines: likelyFailures.slice(0, 30),
  rawReport,
  jsonReport,
  repairBrief: briefReport
}, null, 2));
