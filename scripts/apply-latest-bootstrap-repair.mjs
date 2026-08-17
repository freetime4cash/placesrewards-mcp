import { promises as fs } from "node:fs";
import path from "node:path";
import {
  applyPatchProposal,
  rollbackBackup
} from "/home/placevle/placesrewards-agent-server/lib/code-tools.js";
import { spawn } from "node:child_process";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const LARAVEL_ROOT =
  process.env.PLACESREWARDS_LARAVEL_ROOT ??
  "/home/placevle/app.placesrewards.com";

const PATCH_ROOT =
  process.env.PLACESREWARDS_PATCH_ROOT ??
  path.join(AGENT_ROOT, "data", "patches");

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

const entries = await fs.readdir(PATCH_ROOT, { withFileTypes: true });

const candidates = [];

for (const entry of entries) {
  if (!entry.isDirectory()) continue;

  const manifestPath = path.join(
    PATCH_ROOT,
    entry.name,
    "manifest.json"
  );

  try {
    const manifest = JSON.parse(
      await fs.readFile(manifestPath, "utf8")
    );

    if (manifest.type === "test-bootstrap-repair") {
      candidates.push({
        dir: entry.name,
        manifest,
        manifestPath
      });
    }
  } catch {}
}

candidates.sort((a, b) =>
  String(b.manifest.createdAt ?? "")
    .localeCompare(String(a.manifest.createdAt ?? ""))
);

const selected = candidates[0];

if (!selected) {
  console.error("No test-bootstrap-repair proposal found.");
  process.exit(2);
}

console.log("Selected proposal:");
console.log(JSON.stringify({
  id: selected.manifest.id,
  type: selected.manifest.type,
  createdAt: selected.manifest.createdAt,
  files: selected.manifest.files?.map(f => f.path) ?? []
}, null, 2));

const applied = await applyPatchProposal(selected.manifest.id);

console.log("");
console.log("Backup created:");
console.log(applied.backupDir);

const targetedTests = [
  "tests/Unit/Helpers/ConversionsTest.php",
  "tests/Unit/Services/StampServiceTest.php"
];

const targetedResults = [];

for (const testFile of targetedTests) {
  const result = await run("php", [
    "artisan",
    "test",
    testFile,
    "--colors=never"
  ]);

  targetedResults.push({
    testFile,
    ...result
  });

  console.log("");
  console.log(`=== ${testFile} ===`);
  console.log(result.stdout);
  console.error(result.stderr);
}

const targetedPassed =
  targetedResults.every(r => r.exitCode === 0);

if (!targetedPassed) {
  console.log("");
  console.log("Targeted validation FAILED.");
  console.log("Rolling back automatically...");

  const rollback = await rollbackBackup(
    applied.backupDir
  );

  console.log(JSON.stringify(
    rollback,
    null,
    2
  ));

  console.error("PATCH RESULT: ROLLED BACK");
  process.exit(3);
}

console.log("");
console.log("Targeted bootstrap tests PASSED.");

const fullSuite = await run("php", [
  "artisan",
  "test",
  "--stop-on-failure",
  "--colors=never"
]);

console.log("");
console.log("=== FULL SUITE AFTER BOOTSTRAP REPAIR ===");
console.log(fullSuite.stdout);
console.error(fullSuite.stderr);

console.log("");
console.log(JSON.stringify({
  proposalId: selected.manifest.id,
  appliedFiles: applied.appliedFiles,
  backupDir: applied.backupDir,
  targetedTestsPassed: true,
  fullSuiteExitCode: fullSuite.exitCode,
  expectedRemainingIssue:
    fullSuite.exitCode === 0
      ? null
      : "UpdateServicePhpBinaryTest or another separate failure may remain."
}, null, 2));

console.log("");
console.log("PATCH RESULT: BOOTSTRAP REPAIR APPLIED AND TARGETED TESTS VERIFIED");
