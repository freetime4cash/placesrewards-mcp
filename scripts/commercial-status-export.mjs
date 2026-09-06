import { promises as fs } from "node:fs";
import path from "node:path";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const DATA_DIR = path.join(ROOT, "data");
const RESULT_DIR = path.join(ROOT, "results", "control");
const OUT_FILE = path.join(RESULT_DIR, "commercial-status.json");

async function readJson(file, fallback = null) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return fallback; }
}

function semantic(value) {
  if (!value || typeof value !== "object") return null;
  const { generatedAt, ...rest } = value;
  return JSON.stringify(rest);
}

await fs.mkdir(RESULT_DIR, { recursive: true });
const [scan, contract, publicIntake, queue, drafts, verification] = await Promise.all([
  readJson(path.join(DATA_DIR, "revenue-scan-state.json"), {}),
  readJson(path.join(DATA_DIR, "revenue-contract-state.json"), {}),
  readJson(path.join(DATA_DIR, "public-prospect-opportunities.json"), {}),
  readJson(path.join(DATA_DIR, "commercial-queue.json"), {}),
  readJson(path.join(DATA_DIR, "outreach-drafts.json"), {}),
  readJson(path.join(DATA_DIR, "control-plane-verification.json"), {})
]);

const document = {
  schemaVersion: 1,
  generatedAt: new Date().toISOString(),
  privacy: "aggregate counts and control-plane state only; no merchant identity, contact data, metric values or outreach copy",
  verification: {
    status: verification.status ?? "not-recorded",
    lastTestedHead: verification.lastTestedHead ?? null
  },
  internalRevenueScan: {
    status: scan.status ?? "not-recorded",
    partnersScanned: Number.isFinite(scan.partnersScanned) ? scan.partnersScanned : null,
    opportunitiesFound: Number.isFinite(scan.opportunitiesFound) ? scan.opportunitiesFound : null,
    partnersWithDiagnosticSignals: Number.isFinite(scan.partnersWithDiagnosticSignals) ? scan.partnersWithDiagnosticSignals : null,
    errors: Number.isFinite(scan.errors) ? scan.errors : null
  },
  analyticsContract: {
    status: contract.status ?? "not-recorded",
    partnersSampled: Number.isFinite(contract.partnersSampled) ? contract.partnersSampled : null,
    samplesWithErrors: Number.isFinite(contract.samplesWithErrors) ? contract.samplesWithErrors : null,
    schemaChanged: typeof contract.schemaChanged === "boolean" ? contract.schemaChanged : null
  },
  publicProspectIntake: {
    opportunityCount: Number.isFinite(publicIntake.opportunityCount) ? publicIntake.opportunityCount : 0,
    rejectedCount: Number.isFinite(publicIntake.rejectedCount) ? publicIntake.rejectedCount : 0
  },
  commercialQueue: {
    total: queue.summary?.total ?? 0,
    readyForOutreachDraft: queue.summary?.readyForOutreachDraft ?? 0,
    needsMoreEvidence: queue.summary?.needsMoreEvidence ?? 0,
    awaitingSpecialists: queue.summary?.awaitingSpecialists ?? 0,
    specialistFailed: queue.summary?.specialistFailed ?? 0
  },
  outreachDrafts: {
    draftCount: Number.isFinite(drafts.draftCount) ? drafts.draftCount : 0,
    sendAuthorizedCount: Number.isFinite(drafts.sendAuthorizedCount) ? drafts.sendAuthorizedCount : 0
  }
};

const previous = await readJson(OUT_FILE, null);
const changed = semantic(previous) !== semantic(document);
if (changed) await fs.writeFile(OUT_FILE, JSON.stringify(document, null, 2) + "\n", "utf8");
console.log(JSON.stringify({ ok: true, changed, status: document }, null, 2));
