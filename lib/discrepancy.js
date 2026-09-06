import crypto from "node:crypto";

export const VOLATILE_KEYS = new Set([
  "generatedAt",
  "generated_at",
  "processedAt",
  "processed_at",
  "startedAt",
  "started_at",
  "completedAt",
  "completed_at",
  "updatedAt",
  "updated_at",
  "createdAt",
  "created_at",
  "lastCheckedAt",
  "last_checked_at"
]);

export function normalizeForComparison(value) {
  if (Array.isArray(value)) return value.map(normalizeForComparison);
  if (!value || typeof value !== "object") return value;

  const normalized = {};
  for (const key of Object.keys(value).sort()) {
    if (VOLATILE_KEYS.has(key)) continue;
    normalized[key] = normalizeForComparison(value[key]);
  }
  return normalized;
}

export function stableStringify(value) {
  return JSON.stringify(normalizeForComparison(value));
}

export function fingerprint(value) {
  return crypto.createHash("sha256").update(stableStringify(value)).digest("hex");
}

function errorText(node) {
  const candidates = [node?.error, node?.message, node?.stderr, node?.reason];
  for (const candidate of candidates) {
    if (typeof candidate === "string" && candidate.trim()) return candidate.trim().slice(0, 1000);
  }
  return null;
}

function pushUnique(out, issue) {
  const id = fingerprint(issue);
  if (out.some(existing => existing.id === id)) return;
  out.push({ id, ...issue });
}

function walk(node, path, source, out) {
  if (Array.isArray(node)) {
    node.forEach((item, index) => walk(item, `${path}[${index}]`, source, out));
    return;
  }
  if (!node || typeof node !== "object") return;

  const missingCapability = node.commandExists === false;

  if (missingCapability) {
    pushUnique(out, {
      source,
      path,
      kind: "missing_capability",
      severity: "high",
      summary: `${node.command || "Required command"} is not registered`,
      evidence: { command: node.command ?? null, commandExists: false }
    });
  }

  if (!missingCapability && ["failed", "blocked"].includes(String(node.status ?? "").toLowerCase())) {
    const message = errorText(node);
    pushUnique(out, {
      source,
      path,
      kind: String(node.status).toLowerCase() === "blocked" ? "blocked_work" : "failed_work",
      severity: "high",
      summary: message || `${path || source} reports status ${node.status}`,
      evidence: {
        status: node.status,
        requestType: node.requestType ?? null,
        stage: node.stage ?? null,
        command: node.command ?? null,
        error: message
      }
    });
  }

  for (const [key, child] of Object.entries(node)) {
    walk(child, path ? `${path}.${key}` : key, source, out);
  }
}

export function collectDiscrepancies(document, source = "unknown") {
  const out = [];
  walk(document, "", source, out);
  return out;
}
