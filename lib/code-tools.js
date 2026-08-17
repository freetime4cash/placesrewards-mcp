import { promises as fs } from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";
import crypto from "node:crypto";

const APP_ROOT = process.env.PLACESREWARDS_LARAVEL_ROOT ?? "/home/placevle/app.placesrewards.com";
const AGENT_ROOT = process.env.PLACESREWARDS_AGENT_ROOT ?? "/home/placevle/placesrewards-agent-server";
const PATCH_ROOT = process.env.PLACESREWARDS_PATCH_ROOT ?? path.join(AGENT_ROOT, "data", "patches");
const BACKUP_ROOT = process.env.PLACESREWARDS_BACKUP_ROOT ?? path.join(AGENT_ROOT, "data", "backups");

const ALLOWED_TOP_LEVEL = new Set([
  "app","bootstrap","config","database","resources","routes","tests",
  "composer.json","composer.lock","package.json","package-lock.json",
  "vite.config.js","vite.config.ts","phpunit.xml"
]);

const NEVER_TOUCH = [".env","storage","vendor","node_modules",".git"];

function inside(root, target) {
  const r = path.resolve(root), t = path.resolve(target);
  if (t !== r && !t.startsWith(`${r}${path.sep}`)) throw new Error(`Path escapes root: ${target}`);
  return t;
}

export function isAllowedRelativePath(rel) {
  const n = rel.replace(/\\/g, "/").replace(/^\/+/, "");
  if (!n || n.includes("..")) return false;
  for (const blocked of NEVER_TOUCH) {
    if (n === blocked || n.startsWith(`${blocked}/`) || n.includes(`/${blocked}/`)) return false;
  }
  return ALLOWED_TOP_LEVEL.has(n.split("/")[0]);
}

export async function runCommand(command, args = []) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd: APP_ROOT, env: process.env, shell: false });
    let stdout = "", stderr = "";
    child.stdout?.on("data", d => stdout += d.toString());
    child.stderr?.on("data", d => stderr += d.toString());
    child.on("error", reject);
    child.on("close", code => resolve({
      command: [command, ...args].join(" "),
      exitCode: code ?? -1,
      stdout: stdout.slice(0,30000),
      stderr: stderr.slice(0,30000)
    }));
  });
}

export async function readAppFile(rel, maxChars = 30000) {
  if (!isAllowedRelativePath(rel)) throw new Error(`Reading path not allowed: ${rel}`);
  return (await fs.readFile(inside(APP_ROOT, path.join(APP_ROOT, rel)), "utf8")).slice(0,maxChars);
}

export async function inspectLaravel() {
  const out = { appRoot: APP_ROOT, git: null, artisanAbout: null, routeSummary: null, composer: null };
  try { out.git = await runCommand("git", ["status","--short","--branch"]); }
  catch (e) { out.git = { error: e instanceof Error ? e.message : String(e) }; }
  try { out.artisanAbout = await runCommand("php", ["artisan","about","--only=environment"]); }
  catch (e) { out.artisanAbout = { error: e instanceof Error ? e.message : String(e) }; }
  try { out.routeSummary = await runCommand("php", ["artisan","route:list","--path=api"]); }
  catch (e) { out.routeSummary = { error: e instanceof Error ? e.message : String(e) }; }
  try { out.composer = JSON.parse(await readAppFile("composer.json", 40000)); }
  catch (e) { out.composer = { error: e instanceof Error ? e.message : String(e) }; }
  return out;
}

export async function runSafeTests() {
  const checks = [];
  for (const args of [
    ["artisan","about","--only=environment"],
    ["artisan","route:list","--path=api"],
    ["artisan","config:show","app"]
  ]) {
    try { checks.push(await runCommand("php", args)); }
    catch (e) { checks.push({ command: `php ${args.join(" ")}`, exitCode: -1, stdout: "", stderr: String(e) }); }
  }
  return { passed: checks.every(c => c.exitCode === 0), checks };
}

export async function createPatchProposal({ jobId, files, rationale }) {
  if (!Array.isArray(files) || !files.length) throw new Error("Patch proposal requires files.");
  for (const item of files) {
    if (!item || typeof item.path !== "string" || typeof item.content !== "string") throw new Error("Each patch file needs path and content.");
    if (!isAllowedRelativePath(item.path)) throw new Error(`Patch path not allowed: ${item.path}`);
  }
  await fs.mkdir(PATCH_ROOT, { recursive: true });
  const id = `${Date.now()}-${crypto.randomBytes(4).toString("hex")}`;
  const dir = inside(PATCH_ROOT, path.join(PATCH_ROOT, id));
  await fs.mkdir(dir, { recursive: true });

  const manifest = { id, jobId, createdAt: new Date().toISOString(), rationale: rationale ?? "", files: [] };

  for (const item of files) {
    const rel = item.path.replace(/\\/g, "/");
    const target = inside(dir, path.join(dir, "files", rel));
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.writeFile(target, item.content, "utf8");

    let current = null;
    try { current = await readAppFile(rel, 200000); } catch {}
    manifest.files.push({
      path: rel,
      exists: current !== null,
      originalSha256: current === null ? null : crypto.createHash("sha256").update(current).digest("hex"),
      proposedSha256: crypto.createHash("sha256").update(item.content).digest("hex")
    });
  }

  await fs.writeFile(path.join(dir,"manifest.json"), JSON.stringify(manifest,null,2), "utf8");
  return manifest;
}

export async function applyPatchProposal(proposalId) {
  const proposalDir = inside(PATCH_ROOT, path.join(PATCH_ROOT, proposalId));
  const manifest = JSON.parse(await fs.readFile(path.join(proposalDir,"manifest.json"), "utf8"));
  const stamp = new Date().toISOString().replace(/[:.]/g,"-");
  const backupDir = inside(BACKUP_ROOT, path.join(BACKUP_ROOT, `${proposalId}-${stamp}`));
  await fs.mkdir(backupDir, { recursive: true });

  for (const file of manifest.files) {
    const live = inside(APP_ROOT, path.join(APP_ROOT,file.path));
    const proposed = inside(proposalDir, path.join(proposalDir,"files",file.path));
    const backup = inside(backupDir, path.join(backupDir,file.path));

    try {
      const current = await fs.readFile(live);
      await fs.mkdir(path.dirname(backup), { recursive: true });
      await fs.writeFile(backup, current);
    } catch {}

    const data = await fs.readFile(proposed);
    await fs.mkdir(path.dirname(live), { recursive: true });
    await fs.writeFile(live, data);
  }

  await fs.writeFile(path.join(backupDir,"manifest.json"), JSON.stringify({ proposalId, appliedAt: new Date().toISOString(), files: manifest.files },null,2), "utf8");
  return { proposalId, backupDir, appliedFiles: manifest.files.map(f => f.path) };
}

export async function rollbackBackup(backupDir) {
  const abs = inside(BACKUP_ROOT, backupDir);
  const manifest = JSON.parse(await fs.readFile(path.join(abs,"manifest.json"), "utf8"));

  for (const file of manifest.files) {
    const backup = inside(abs, path.join(abs,file.path));
    const live = inside(APP_ROOT, path.join(APP_ROOT,file.path));

    try {
      const data = await fs.readFile(backup);
      await fs.mkdir(path.dirname(live), { recursive: true });
      await fs.writeFile(live, data);
    } catch {
      try { await fs.unlink(live); } catch {}
    }
  }
  return { rolledBack: true, backupDir: abs };
}
