import { execFileSync } from "node:child_process";
import { promises as fs } from "node:fs";
import { normalizeForComparison } from "../lib/discrepancy.js";

function runGit(args) {
  return execFileSync("git", args, { encoding: "utf8" }).trim();
}

function parseJson(text) {
  try { return JSON.parse(text); }
  catch { return null; }
}

function equivalentJson(a, b) {
  const left = parseJson(a);
  const right = parseJson(b);
  if (left === null || right === null) return a === b;
  return JSON.stringify(normalizeForComparison(left)) === JSON.stringify(normalizeForComparison(right));
}

const staged = runGit(["diff", "--cached", "--name-only", "--", "requests/campaigns", "results/campaigns"])
  .split("\n")
  .map(v => v.trim())
  .filter(Boolean);

const meaningful = [];
const volatileOnly = [];

for (const file of staged) {
  if (file.startsWith("requests/campaigns/")) {
    meaningful.push(file);
    continue;
  }
  if (!file.endsWith(".json")) {
    meaningful.push(file);
    continue;
  }

  let current = "";
  try { current = await fs.readFile(file, "utf8"); }
  catch {
    meaningful.push(file);
    continue;
  }

  let previous = null;
  try { previous = runGit(["show", `HEAD:${file}`]); }
  catch {}

  if (previous === null || !equivalentJson(current, previous)) meaningful.push(file);
  else volatileOnly.push(file);
}

console.log(JSON.stringify({ staged, meaningful, volatileOnly }, null, 2));
process.exit(meaningful.length ? 0 : 3);
