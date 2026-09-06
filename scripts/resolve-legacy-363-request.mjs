import { promises as fs } from "node:fs";
import path from "node:path";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const RESULT_DIR = path.join(ROOT, "results", "campaigns");
const DATA_DIR = path.join(ROOT, "data");
const PROCESSED_FILE = path.join(DATA_DIR, "github-campaign-processed.json");
const LEGACY_REQUEST_ID = "chatgpt-363-foundation-install-20260819-1020";
const DIRECT_RESULT = path.join(RESULT_DIR, "363-foundation-demo-install-v2-result.json");
const LEGACY_RESULT = path.join(RESULT_DIR, `${LEGACY_REQUEST_ID}.json`);

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

const direct = await readJson(DIRECT_RESULT, null);
if (!direct) {
  console.log(JSON.stringify({ ok: false, resolved: false, reason: "direct_installer_result_missing" }));
  process.exit(0);
}

const directStatus = String(direct.status ?? "").toLowerCase();
if (!directStatus.startsWith("completed")) {
  console.log(JSON.stringify({ ok: true, resolved: false, reason: "direct_installer_not_complete", directStatus }));
  process.exit(0);
}

const failedRecords = Array.isArray(direct.records)
  ? direct.records.filter(item => String(item?.status ?? "").toLowerCase() === "failed")
  : [];
if (failedRecords.length) {
  console.log(JSON.stringify({ ok: true, resolved: false, reason: "direct_installer_has_failed_records", failedRecords: failedRecords.length }));
  process.exit(0);
}

const result = {
  requestId: LEGACY_REQUEST_ID,
  campaignName: "363 Foundation Complete Demo Campaign",
  status: "completed",
  requestType: "artisan_363_demo",
  processedAt: new Date().toISOString(),
  command: "placesrewards:install-363-demo",
  commandExists: false,
  resolvedBy: "verified_agent_side_installer",
  resolution: "The legacy Artisan command is unnecessary because the controlled agent-side 363 installer completed the requested installation and reported no failed records.",
  evidence: {
    directInstallerResult: "results/campaigns/363-foundation-demo-install-v2-result.json",
    directInstallerStatus: direct.status,
    recordCount: Array.isArray(direct.records) ? direct.records.length : null,
    failedRecordCount: failedRecords.length
  },
  stages: [
    {
      stage: "legacy-command-reconciliation",
      exitCode: 0,
      status: "completed",
      note: "Satisfied by existing verified installer; no production application patch required."
    }
  ]
};

await fs.writeFile(LEGACY_RESULT, JSON.stringify(result, null, 2) + "\n", "utf8");
const processed = await readJson(PROCESSED_FILE, {});
processed[LEGACY_REQUEST_ID] = {
  ...(processed[LEGACY_REQUEST_ID] ?? {}),
  status: "completed",
  processedAt: result.processedAt,
  resolvedBy: result.resolvedBy
};
await fs.mkdir(DATA_DIR, { recursive: true });
await fs.writeFile(PROCESSED_FILE, JSON.stringify(processed, null, 2) + "\n", "utf8");

console.log(JSON.stringify({ ok: true, resolved: true, requestId: LEGACY_REQUEST_ID, directStatus: direct.status }, null, 2));
