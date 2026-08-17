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
const SNAPSHOT_POLICY = path.join(AGENT_ROOT, "data", "source-control.json");

await fs.mkdir(REPORT_DIR, { recursive: true });

function run(command, args, cwd = LARAVEL_ROOT) {
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

async function exists(rel) {
  try {
    await fs.access(path.join(LARAVEL_ROOT, rel));
    return true;
  } catch {
    return false;
  }
}

async function listFiles(root, max = 5000) {
  const out = [];

  async function walk(dir, rel = "") {
    if (out.length >= max) return;

    let entries = [];
    try {
      entries = await fs.readdir(dir, { withFileTypes: true });
    } catch {
      return;
    }

    for (const entry of entries) {
      if (out.length >= max) break;

      if (["vendor","node_modules","storage",".git"].includes(entry.name)) {
        continue;
      }

      const relative = path.join(rel, entry.name);
      const absolute = path.join(dir, entry.name);

      if (entry.isDirectory()) {
        await walk(absolute, relative);
      } else if (entry.isFile()) {
        out.push(relative.replace(/\\/g, "/"));
      }
    }
  }

  await walk(root);
  return out;
}

const stamp = new Date().toISOString().replace(/[:.]/g, "-");
const reportPath = path.join(REPORT_DIR, `autonomous-build-intake-${stamp}.json`);

const [
  about,
  routes,
  migrations,
  tests,
  files
] = await Promise.all([
  run("php", ["artisan", "about", "--only=environment"]),
  run("php", ["artisan", "route:list", "--path=api"]),
  run("php", ["artisan", "migrate:status"]),
  run("php", ["artisan", "test", "--stop-on-failure"]),
  listFiles(LARAVEL_ROOT)
]);

let sourceControl = null;
try {
  sourceControl = JSON.parse(await fs.readFile(SNAPSHOT_POLICY, "utf8"));
} catch {}

const routeLines = routes.stdout
  .split(/\r?\n/)
  .map(line => line.trim())
  .filter(Boolean);

const routeText = routes.stdout.toLowerCase();
const fileText = files.join("\n").toLowerCase();

const capabilityChecks = [
  ["agent_api", routeText.includes("api/agent/v1/health")],
  ["partners", routeText.includes("/partners")],
  ["members", routeText.includes("/members")],
  ["rewards", routeText.includes("/rewards")],
  ["stamp_cards", routeText.includes("stamp-cards")],
  ["vouchers", routeText.includes("vouchers")],
  ["tiers", routeText.includes("/tiers")],
  ["transactions", routeText.includes("/transactions")],
  ["staff", routeText.includes("/staff")],
  ["shopify", routeText.includes("shopify")],
  ["swagger", routeText.includes("swagger") || routeText.includes("documentation")],
  ["horizon", routeText.includes("horizon/api")],
  ["scratch_cards",
    routeText.includes("scratch-card") ||
    routeText.includes("scratch_cards") ||
    fileText.includes("scratchcard") ||
    fileText.includes("scratch-card")],
  ["referrals",
    routeText.includes("referral") ||
    fileText.includes("referral")],
  ["reviews",
    routeText.includes("review") ||
    fileText.includes("review")],
  ["giveaways",
    routeText.includes("giveaway") ||
    fileText.includes("giveaway") ||
    fileText.includes("contest")],
  ["directory_discovery",
    routeText.includes("discover") ||
    fileText.includes("discover")]
];

const capabilities = Object.fromEntries(capabilityChecks);

const backlog = [];

function add(priority, category, title, reason, mode = "inspect_then_patch") {
  backlog.push({
    id: `${category}-${backlog.length + 1}`,
    priority,
    category,
    title,
    reason,
    mode,
    productionWriteAllowedAutomatically: false
  });
}

if (!capabilities.scratch_cards) {
  add(
    100,
    "product",
    "Complete Scratch Card capability",
    "No clear scratch-card route/file surface was detected in the current Laravel inventory."
  );
}

if (!capabilities.referrals) {
  add(
    95,
    "growth",
    "Complete Referral capability",
    "No clear referral route/file surface was detected."
  );
}

if (!capabilities.reviews) {
  add(
    90,
    "growth",
    "Complete Reviews/Reputation capability",
    "No clear review/reputation route/file surface was detected."
  );
}

if (!capabilities.giveaways) {
  add(
    88,
    "engagement",
    "Complete Giveaway/Contest capability",
    "No clear giveaway/contest route/file surface was detected."
  );
}

if (!capabilities.directory_discovery) {
  add(
    85,
    "network",
    "Strengthen cross-business discovery",
    "No clear discovery route/file surface was detected."
  );
}

if (tests.exitCode !== 0) {
  add(
    100,
    "quality",
    "Resolve failing Laravel test suite",
    "php artisan test returned a non-zero exit code.",
    "diagnose_only"
  );
} else {
  add(
    70,
    "quality",
    "Expand regression coverage",
    "Current safe test suite passes; next priority is coverage around Agent API and merchant-critical workflows."
  );
}

if (!sourceControl || sourceControl.mode !== "compressed-snapshot") {
  add(
    98,
    "safety",
    "Restore quota-safe snapshot policy",
    "The expected compressed-snapshot source-control policy was not detected.",
    "diagnose_only"
  );
}

add(
  82,
  "platform",
  "Audit Agent API tool coverage against Laravel capabilities",
  "The server should expose only deliberate, permission-scoped tools for the live platform."
);

add(
  78,
  "merchant_roi",
  "Add merchant ROI observability",
  "Prioritize measurable acquisition, engagement, redemption, retention, and cross-business discovery metrics."
);

add(
  75,
  "performance",
  "Run performance and queue health audit",
  "The platform uses Horizon and should have explicit latency/queue/failed-job checks."
);

backlog.sort((a, b) => b.priority - a.priority);

const report = {
  version: "0.6.0",
  generatedAt: new Date().toISOString(),
  laravelRoot: LARAVEL_ROOT,
  productionModified: false,
  environmentCheck: {
    passed: about.exitCode === 0,
    command: about.command
  },
  routeInventory: {
    exitCode: routes.exitCode,
    routeLines: routeLines.length
  },
  migrationStatus: {
    exitCode: migrations.exitCode,
    pendingDetected:
      migrations.stdout.toLowerCase().includes("pending")
  },
  tests: {
    exitCode: tests.exitCode,
    passed: tests.exitCode === 0
  },
  sourceInventory: {
    filesScanned: files.length
  },
  capabilities,
  sourceControl,
  backlog
};

await fs.writeFile(reportPath, JSON.stringify(report, null, 2), "utf8");
await fs.writeFile(BACKLOG_FILE, JSON.stringify(backlog, null, 2), "utf8");

console.log(JSON.stringify({
  result: "PASS",
  version: report.version,
  productionModified: false,
  filesScanned: files.length,
  routeLines: routeLines.length,
  testsPassed: tests.exitCode === 0,
  pendingMigrationsDetected: report.migrationStatus.pendingDetected,
  backlogItems: backlog.length,
  topPriorities: backlog.slice(0, 8),
  report: reportPath,
  backlog: BACKLOG_FILE
}, null, 2));
