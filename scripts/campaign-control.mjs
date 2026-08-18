import { promises as fs } from "node:fs";
import path from "node:path";

const AGENT_ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const API_BASE = (process.env.PLACESREWARDS_API_URL ?? "https://app.placesrewards.com/api/agent/v1").replace(/\/+$/, "");
const AGENT_KEY = process.env.PLACESREWARDS_AGENT_KEY;
if (!AGENT_KEY) { console.error("PLACESREWARDS_AGENT_KEY is not set."); process.exit(2); }

const DATA = path.join(AGENT_ROOT, "data");
const CAP_FILE = path.join(DATA, "campaign-capabilities.json");
const TEMPLATE = path.join(DATA, "campaign-template.json");

async function request(endpoint, options = {}) {
  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Agent-Key": AGENT_KEY,
      ...(options.headers ?? {})
    }
  });
  const text = await response.text();
  let data = text;
  try { data = text ? JSON.parse(text) : null; } catch {}
  if (!response.ok) throw new Error(`HTTP ${response.status}: ${typeof data === "string" ? data : JSON.stringify(data)}`);
  return data;
}

function extractTools(value, out = [], lineage = []) {
  if (Array.isArray(value)) {
    for (const item of value) extractTools(item, out, lineage);
    return out;
  }
  if (!value || typeof value !== "object") return out;

  const keys = Object.keys(value);
  const methodKey = keys.find(k => ["method","http_method","httpmethod"].includes(k.toLowerCase()));
  const pathKey = keys.find(k => ["path","route","endpoint"].includes(k.toLowerCase()));
  const nameKey = keys.find(k => ["name","tool","id","title"].includes(k.toLowerCase()));

  if (methodKey && pathKey && typeof value[methodKey] === "string" && typeof value[pathKey] === "string") {
    out.push({
      name: nameKey && typeof value[nameKey] === "string" ? value[nameKey] : lineage.join("."),
      method: value[methodKey].toUpperCase(),
      path: value[pathKey],
      raw: value
    });
  }

  for (const [key, child] of Object.entries(value)) {
    if (child && typeof child === "object") extractTools(child, out, [...lineage, key]);
  }
  return out;
}

function campaignRelevant(tool) {
  const text = `${tool.name} ${tool.method} ${tool.path}`.toLowerCase();
  return ["campaign","reward","stamp","voucher","card","club","tier","partner","giveaway","contest","scratch","referral","review"]
    .some(word => text.includes(word));
}

async function refreshCapabilities() {
  const toolsPayload = await request("/tools");
  const extracted = extractTools(toolsPayload);
  const relevant = extracted.filter(campaignRelevant);
  await fs.mkdir(DATA, { recursive: true });
  await fs.writeFile(path.join(DATA, "agent-tools-live.json"), JSON.stringify(toolsPayload, null, 2), "utf8");

  const payload = {
    generatedAt: new Date().toISOString(),
    apiBase: API_BASE,
    totalDiscoveredTools: extracted.length,
    campaignRelevantTools: relevant
  };
  await fs.writeFile(CAP_FILE, JSON.stringify(payload, null, 2), "utf8");
  return payload;
}

async function makeTemplate() {
  const template = {
    version: "1.0",
    approved: false,
    campaignName: "Example PlacesRewards Campaign",
    partnerId: "REPLACE_WITH_PARTNER_ID",
    objective: "Acquire, engage, retain and reactivate customers with measurable ROI.",
    steps: [
      {
        name: "Example validated Agent API action",
        method: "POST",
        path: "REPLACE_WITH_DISCOVERED_AGENT_API_PATH",
        body: {}
      }
    ],
    safety: {
      requireApprovedTrue: true,
      requireDiscoveredPath: true,
      logEveryAction: true
    }
  };
  await fs.writeFile(TEMPLATE, JSON.stringify(template, null, 2), "utf8");
  return TEMPLATE;
}

async function executePlan(file) {
  const plan = JSON.parse(await fs.readFile(file, "utf8"));
  if (plan.approved !== true) throw new Error("Campaign plan is not approved.");

  const caps = JSON.parse(await fs.readFile(CAP_FILE, "utf8"));
  const discovered = caps.campaignRelevantTools ?? [];
  const audit = { campaignName: plan.campaignName ?? "Unnamed campaign", startedAt: new Date().toISOString(), steps: [] };

  for (const step of plan.steps ?? []) {
    const method = String(step.method ?? "").toUpperCase();
    const endpoint = String(step.path ?? "");
    const allowed = discovered.some(tool => tool.method === method && (
      tool.path === endpoint || endpoint.startsWith(tool.path.replace(/\{[^}]+\}/g, ""))
    ));

    if (!allowed) {
      audit.steps.push({ ...step, status: "blocked", reason: "Method/path not present in discovered Agent API tools." });
      continue;
    }

    try {
      const data = await request(endpoint, {
        method,
        body: ["GET","HEAD"].includes(method) ? undefined : JSON.stringify(step.body ?? {})
      });
      audit.steps.push({ name: step.name, method, path: endpoint, status: "completed", response: data });
    } catch (error) {
      audit.steps.push({ name: step.name, method, path: endpoint, status: "failed", error: error instanceof Error ? error.message : String(error) });
      break;
    }
  }

  audit.completedAt = new Date().toISOString();
  const auditFile = path.join(DATA, "reports", `campaign-execution-${Date.now()}.json`);
  await fs.mkdir(path.dirname(auditFile), { recursive: true });
  await fs.writeFile(auditFile, JSON.stringify(audit, null, 2), "utf8");
  console.log(JSON.stringify({ auditFile, audit }, null, 2));
}

const [command, arg] = process.argv.slice(2);

switch (command) {
  case "refresh":
    console.log(JSON.stringify(await refreshCapabilities(), null, 2));
    break;
  case "capabilities":
    try { console.log(await fs.readFile(CAP_FILE, "utf8")); }
    catch { console.log(JSON.stringify(await refreshCapabilities(), null, 2)); }
    break;
  case "template":
    console.log(await makeTemplate());
    break;
  case "execute":
    if (!arg) { console.error("Usage: node campaign-control.mjs execute <plan.json>"); process.exit(2); }
    await executePlan(path.resolve(arg));
    break;
  default:
    console.log("Usage: refresh | capabilities | template | execute <plan.json>");
}
