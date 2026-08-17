import { promises as fs } from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";
import crypto from "node:crypto";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const LARAVEL_ROOT =
  process.env.PLACESREWARDS_LARAVEL_ROOT ??
  "/home/placevle/app.placesrewards.com";

const PATCH_ROOT =
  process.env.PLACESREWARDS_PATCH_ROOT ??
  path.join(AGENT_ROOT, "data", "patches");

const REPORT_DIR = path.join(AGENT_ROOT, "data", "reports");

await fs.mkdir(REPORT_DIR, { recursive: true });
await fs.mkdir(PATCH_ROOT, { recursive: true });

const targets = [
  "tests/Pest.php",
  "tests/TestCase.php",
  "tests/Unit/Helpers/ConversionsTest.php",
  "tests/Unit/Services/StampServiceTest.php",
  "tests/Unit/UpdateServicePhpBinaryTest.php"
];

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

async function read(rel) {
  try {
    return await fs.readFile(path.join(LARAVEL_ROOT, rel), "utf8");
  } catch {
    return null;
  }
}

function sha(value) {
  return crypto.createHash("sha256").update(value).digest("hex");
}

function addLaravelTestCaseBootstrap(content) {
  const normalized = content.replace(/\r\n/g, "\n");

  if (/uses\s*\(\s*Tests\\TestCase::class\s*\)/s.test(normalized)) {
    return normalized;
  }

  const phpTag = normalized.match(/^<\?php\s*/);

  if (!phpTag) {
    throw new Error("Unexpected Pest test format; missing <?php");
  }

  const insertAt = phpTag[0].length;

  return (
    normalized.slice(0, insertAt) +
    "\nuses(Tests\\TestCase::class);\n" +
    normalized.slice(insertAt)
  );
}

const files = {};

for (const rel of targets) {
  files[rel] = await read(rel);
}

const targeted = {};

for (const rel of [
  "tests/Unit/Helpers/ConversionsTest.php",
  "tests/Unit/Services/StampServiceTest.php",
  "tests/Unit/UpdateServicePhpBinaryTest.php"
]) {
  targeted[rel] = await run("php", [
    "artisan",
    "test",
    rel,
    "--colors=never"
  ]);
}

const pest = files["tests/Pest.php"] ?? "";
const conversions = files["tests/Unit/Helpers/ConversionsTest.php"] ?? "";
const stamp = files["tests/Unit/Services/StampServiceTest.php"] ?? "";

const globalUnitBootstrap =
  /uses\s*\(\s*Tests\\TestCase::class\s*\).*in\s*\([^)]*['"]Unit['"]/s.test(pest) ||
  /pest\(\)\s*->\s*extend\s*\(\s*Tests\\TestCase::class\s*\).*in\s*\([^)]*['"]Unit['"]/s.test(pest);

const conversionsOutput =
  `${targeted["tests/Unit/Helpers/ConversionsTest.php"].stdout}\n${targeted["tests/Unit/Helpers/ConversionsTest.php"].stderr}`;

const stampOutput =
  `${targeted["tests/Unit/Services/StampServiceTest.php"].stdout}\n${targeted["tests/Unit/Services/StampServiceTest.php"].stderr}`;

const conversionsNeedsBootstrap =
  !/uses\s*\(\s*Tests\\TestCase::class\s*\)/s.test(conversions) &&
  /BindingResolutionException|Target class .* does not exist/i.test(conversionsOutput);

const stampNeedsBootstrap =
  !/uses\s*\(\s*Tests\\TestCase::class\s*\)/s.test(stamp) &&
  /facade root has not been set/i.test(stampOutput);

const proposalFiles = [];
const reasoning = [];

if (!globalUnitBootstrap && conversionsNeedsBootstrap) {
  proposalFiles.push({
    path: "tests/Unit/Helpers/ConversionsTest.php",
    content: addLaravelTestCaseBootstrap(conversions)
  });

  reasoning.push(
    "ConversionsTest requires Laravel application/container bootstrap."
  );
}

if (!globalUnitBootstrap && stampNeedsBootstrap) {
  proposalFiles.push({
    path: "tests/Unit/Services/StampServiceTest.php",
    content: addLaravelTestCaseBootstrap(stamp)
  });

  reasoning.push(
    "StampServiceTest uses Laravel facades but the application is not bootstrapped."
  );
}

let manifest = null;

if (proposalFiles.length > 0) {
  const proposalId =
    `${Date.now()}-${crypto.randomBytes(4).toString("hex")}`;

  const proposalDir = path.join(PATCH_ROOT, proposalId);

  await fs.mkdir(path.join(proposalDir, "files"), {
    recursive: true
  });

  manifest = {
    id: proposalId,
    type: "test-bootstrap-repair",
    createdAt: new Date().toISOString(),
    productionModified: false,
    rationale:
      "Repair only the Laravel/Pest bootstrap failures. " +
      "UpdateServicePhpBinaryTest remains separate.",
    reasoning,
    files: []
  };

  for (const item of proposalFiles) {
    const target = path.join(
      proposalDir,
      "files",
      item.path
    );

    await fs.mkdir(path.dirname(target), {
      recursive: true
    });

    await fs.writeFile(
      target,
      item.content,
      "utf8"
    );

    const original = files[item.path] ?? "";

    manifest.files.push({
      path: item.path,
      originalSha256: sha(original),
      proposedSha256: sha(item.content)
    });
  }

  await fs.writeFile(
    path.join(proposalDir, "manifest.json"),
    JSON.stringify(manifest, null, 2),
    "utf8"
  );
}

const report = {
  version: "0.6.2",
  generatedAt: new Date().toISOString(),
  productionModified: false,
  detection: {
    globalUnitBootstrap,
    conversionsNeedsBootstrap,
    stampNeedsBootstrap
  },
  proposedRepair: manifest,
  deferredIssue: {
    test:
      "tests/Unit/UpdateServicePhpBinaryTest.php",
    reason:
      "Separate assertion mismatch; intentionally excluded from bootstrap repair."
  }
};

const reportPath = path.join(
  REPORT_DIR,
  `test-bootstrap-repair-${new Date()
    .toISOString()
    .replace(/[:.]/g, "-")}.json`
);

await fs.writeFile(
  reportPath,
  JSON.stringify(report, null, 2),
  "utf8"
);

console.log(JSON.stringify({
  result:
    manifest
      ? "ISOLATED_REPAIR_PROPOSAL_READY"
      : "NO_PROPOSAL_CREATED",
  productionModified: false,
  proposalId: manifest?.id ?? null,
  proposedFiles:
    manifest?.files.map(f => f.path) ?? [],
  deferredIssue: report.deferredIssue,
  report: reportPath
}, null, 2));

if (!manifest) {
  process.exit(3);
}
