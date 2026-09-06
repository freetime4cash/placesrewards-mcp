import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import crypto from "node:crypto";
import { gunzipSync } from "node:zlib";
import path from "node:path";

const root = path.resolve(".");
const manifestPath = path.join(root, "artifacts/laravel/Install363EmpireDemo.php.parts.json");
const manifest = JSON.parse(await readFile(manifestPath, "utf8"));

assert.equal(manifest.schemaVersion, 1);
assert.equal(manifest.encoding, "gzip-base64");
assert.equal(manifest.parts.length, 5);

const pieces = [];
for (const relative of manifest.parts) {
  assert(relative.startsWith("artifacts/laravel/"));
  assert(!relative.includes(".."));
  pieces.push((await readFile(path.join(root, relative), "utf8")).replace(/\s+/g, ""));
}

const decoded = gunzipSync(Buffer.from(pieces.join(""), "base64"));
const digest = crypto.createHash("sha256").update(decoded).digest("hex");
const source = decoded.toString("utf8");

assert.equal(decoded.length, 95104, "Canonical installer byte count changed.");
assert.equal(decoded.length, manifest.decodedBytes, "Manifest byte count does not match reconstructed installer.");
assert.equal(digest, "0e9696d715ee81fa47e6d3d635a32dfa9bfd7f26085c24bd400eb9c4f27cb500", "Canonical installer SHA-256 changed.");
assert.equal(digest, manifest.decodedSha256, "Manifest SHA-256 does not match reconstructed installer.");
assert(source.includes("final class Install363EmpireDemo extends Command"), "Expected installer class is missing.");
assert(source.includes("placesrewards:install-363-demo"), "Expected Artisan command signature is missing.");
assert(source.includes("placesrewards_363_demo_registry"), "Expected installer registry is missing.");
assert(source.includes("--dry-run"), "Expected dry-run safeguard is missing.");
assert(source.includes("--remove"), "Expected targeted removal safeguard is missing.");

console.log(JSON.stringify({
  ok: true,
  decodedBytes: decoded.length,
  decodedSha256: digest,
  command: "placesrewards:install-363-demo",
  message: "Canonical 363 installer reconstructed and verified byte-for-byte."
}, null, 2));
