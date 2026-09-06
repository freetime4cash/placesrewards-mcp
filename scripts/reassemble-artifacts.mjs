import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { gunzipSync } from "node:zlib";

const ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const MANIFESTS = [
  "artifacts/laravel/Install363EmpireDemo.php.parts.json"
];

const sha256 = value => crypto.createHash("sha256").update(value).digest("hex");

function insideRoot(relative) {
  const normalized = String(relative ?? "").replace(/\\/g, "/").replace(/^\/+/, "");
  if (!normalized.startsWith("artifacts/laravel/") || normalized.includes("..")) {
    throw new Error(`Artifact path is not allowed: ${relative}`);
  }
  const absolute = path.resolve(ROOT, normalized);
  const allowed = path.resolve(ROOT, "artifacts/laravel");
  if (!absolute.startsWith(`${allowed}${path.sep}`)) throw new Error(`Artifact path escapes allowed root: ${relative}`);
  return absolute;
}

const results = [];
for (const manifestPath of MANIFESTS) {
  const manifest = JSON.parse(await fs.readFile(insideRoot(manifestPath), "utf8"));
  if (manifest.schemaVersion !== 1) throw new Error(`Unsupported artifact manifest schema: ${manifestPath}`);
  if (manifest.encoding !== "gzip-base64") throw new Error(`Unsupported artifact encoding: ${manifest.encoding}`);
  if (!Array.isArray(manifest.parts) || !manifest.parts.length) throw new Error(`Artifact manifest has no parts: ${manifestPath}`);

  const chunks = [];
  for (const part of manifest.parts) chunks.push((await fs.readFile(insideRoot(part), "utf8")).replace(/\s+/g, ""));
  const base64 = chunks.join("");
  const decoded = gunzipSync(Buffer.from(base64, "base64"));
  const digest = sha256(decoded);

  if (decoded.length !== manifest.decodedBytes) {
    throw new Error(`Decoded byte count mismatch for ${manifest.output}: expected ${manifest.decodedBytes}, got ${decoded.length}`);
  }
  if (digest !== manifest.decodedSha256) {
    throw new Error(`Decoded SHA-256 mismatch for ${manifest.output}: expected ${manifest.decodedSha256}, got ${digest}`);
  }

  const output = insideRoot(manifest.output);
  await fs.mkdir(path.dirname(output), { recursive: true });
  let current = null;
  try { current = (await fs.readFile(output, "utf8")).replace(/\s+/g, ""); } catch {}
  if (current !== base64) await fs.writeFile(output, base64 + "\n", "utf8");

  results.push({ manifest: manifestPath, output: manifest.output, decodedBytes: decoded.length, decodedSha256: digest, changed: current !== base64 });
}

console.log(JSON.stringify({ ok: true, artifacts: results }, null, 2));
