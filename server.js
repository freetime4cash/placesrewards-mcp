import http from "node:http";
import { promises as fs } from "node:fs";
import path from "node:path";
import { URL } from "node:url";
import { createRuntime, summarizeJobs } from "./lib/runtime.js";

const PORT = Number(process.env.PORT ?? 3000);
const CONTROL_TOKEN = process.env.SERVER_CONTROL_TOKEN;
const CRON_SECRET = process.env.CRON_SECRET;
const APP_BASE_PATH = (process.env.APP_BASE_PATH ?? "/agent").replace(/\/+$/, "");
const AGENT_ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const VERSION = "2.1.0";

if (!CONTROL_TOKEN || CONTROL_TOKEN.length < 32) throw new Error("SERVER_CONTROL_TOKEN must be at least 32 characters.");
if (!CRON_SECRET || CRON_SECRET.length < 32) throw new Error("CRON_SECRET must be at least 32 characters.");

function send(res, status, body) {
  const data = JSON.stringify(body, null, 2);
  res.writeHead(status, { "Content-Type": "application/json", "Content-Length": Buffer.byteLength(data) });
  res.end(data);
}

async function readJson(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return chunks.length ? JSON.parse(Buffer.concat(chunks).toString("utf8")) : {};
}

async function readJsonFile(file) {
  try { return JSON.parse(await fs.readFile(file, "utf8")); }
  catch { return null; }
}

function readLocalState(file) {
  return readJsonFile(path.join(AGENT_ROOT, "data", file));
}

function readResultState(...parts) {
  return readJsonFile(path.join(AGENT_ROOT, "results", ...parts));
}

function bearer(req) {
  const h = req.headers.authorization ?? "";
  return h.startsWith("Bearer ") ? h.slice(7) : "";
}

function normalize(pathname) {
  if (!APP_BASE_PATH || APP_BASE_PATH === "/") return pathname || "/";
  if (pathname === APP_BASE_PATH) return "/";
  if (pathname.startsWith(`${APP_BASE_PATH}/`)) return pathname.slice(APP_BASE_PATH.length) || "/";
  return pathname || "/";
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url ?? "/", `http://${req.headers.host ?? "localhost"}`);
    const pathname = normalize(url.pathname);

    if (req.method === "GET" && pathname === "/health") {
      const [verification, revenueScan, commercial] = await Promise.all([
        readLocalState("control-plane-verification.json"),
        readLocalState("revenue-scan-state.json"),
        readResultState("control", "commercial-status.json")
      ]);
      const verificationFailed = verification?.status === "failed";
      const scanFailed = revenueScan?.status === "failed";
      const commercialFailed = (commercial?.commercialQueue?.specialistFailed ?? 0) > 0;
      return send(res, 200, {
        ok: !verificationFailed && !scanFailed && !commercialFailed,
        service: "placesrewards-agent-server",
        version: VERSION,
        architecture: "autonomous-company-os",
        basePath: APP_BASE_PATH,
        verification: verification ? {
          status: verification.status ?? "unknown",
          lastTestedHead: verification.lastTestedHead ?? null,
          completedAt: verification.completedAt ?? null
        } : { status: "not-yet-recorded" },
        revenueScanner: revenueScan ? {
          status: revenueScan.status ?? "unknown",
          completedAt: revenueScan.completedAt ?? null
        } : { status: "not-yet-recorded" },
        commercial: commercial ? {
          publicProspects: commercial.publicProspectIntake?.opportunityCount ?? 0,
          readyForOutreachDraft: commercial.commercialQueue?.readyForOutreachDraft ?? 0,
          needsMoreEvidence: commercial.commercialQueue?.needsMoreEvidence ?? 0,
          awaitingSpecialists: commercial.commercialQueue?.awaitingSpecialists ?? 0,
          specialistFailed: commercial.commercialQueue?.specialistFailed ?? 0,
          unsentDrafts: commercial.outreachDrafts?.draftCount ?? 0,
          sendAuthorized: commercial.outreachDrafts?.sendAuthorizedCount ?? 0
        } : { status: "not-yet-recorded" }
      });
    }

    if (req.method === "POST" && pathname === "/cron/worker") {
      if (req.headers["x-cron-secret"] !== CRON_SECRET) return send(res, 401, { ok: false, error: "Unauthorized" });
      const { orchestrator } = createRuntime();
      const processed = await orchestrator.runUntilIdle();
      return send(res, 200, { ok: true, processed: processed.length });
    }

    if (bearer(req) !== CONTROL_TOKEN) return send(res, 401, { ok: false, error: "Unauthorized" });

    const { orchestrator } = createRuntime();

    if (req.method === "GET" && pathname === "/agents") {
      return send(res, 200, { ok: true, agents: orchestrator.listAgents() });
    }

    if (req.method === "GET" && pathname === "/status") {
      const [jobs, verification, revenueScan, commercial] = await Promise.all([
        orchestrator.listJobs(),
        readLocalState("control-plane-verification.json"),
        readLocalState("revenue-scan-state.json"),
        readResultState("control", "commercial-status.json")
      ]);
      return send(res, 200, {
        ok: true,
        version: VERSION,
        status: summarizeJobs(jobs),
        verification,
        revenueScan,
        commercial,
        waiting: jobs.filter(j => j.status === "waiting_approval").map(j => ({ id: j.id, agent: j.agent, objective: j.objective, result: j.result }))
      });
    }

    if (req.method === "POST" && pathname === "/run") {
      const body = await readJson(req);
      const objective = typeof body.objective === "string" && body.objective.trim()
        ? body.objective.trim()
        : "Inspect the live Laravel Places Rewards platform and continue the controlled autonomous build.";

      const job = await orchestrator.enqueue({
        agent: "command",
        objective,
        input: body.input && typeof body.input === "object" ? body.input : undefined,
        priority: 100,
        autonomyLevel: 2
      });

      return send(res, 202, { ok: true, job });
    }

    if (req.method === "POST" && pathname === "/worker") {
      const processed = await orchestrator.runUntilIdle();
      return send(res, 200, { ok: true, processed: processed.length });
    }

    const match = pathname.match(/^\/approve\/([^/]+)$/);
    if (req.method === "POST" && match) {
      const job = await orchestrator.approveJob(decodeURIComponent(match[1]), 3);
      return send(res, 200, { ok: true, job });
    }

    return send(res, 404, { ok: false, error: "Not found" });
  } catch (error) {
    return send(res, 500, { ok: false, error: error instanceof Error ? error.message : String(error) });
  }
});

server.listen(PORT, "0.0.0.0", () => {
  console.log(`PlacesRewards Agent Server v${VERSION} listening on port ${PORT}`);
});
