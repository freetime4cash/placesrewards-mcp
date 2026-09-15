import { promises as fs } from "node:fs";
import path from "node:path";
import crypto from "node:crypto";

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

export class FileJobStore {
  constructor(filePath) {
    this.filePath = filePath;
    this.lockPath = `${filePath}.lock`;
  }

  async readAll() {
    try {
      const parsed = JSON.parse(await fs.readFile(this.filePath, "utf8"));
      if (!Array.isArray(parsed)) throw new Error("Job store root must be an array.");
      return parsed;
    } catch (error) {
      if (error?.code === "ENOENT") return [];
      throw error;
    }
  }

  async acquireLock({ timeoutMs = 10_000, staleMs = 120_000 } = {}) {
    const started = Date.now();
    await fs.mkdir(path.dirname(this.filePath), { recursive: true });

    while (Date.now() - started < timeoutMs) {
      try {
        const handle = await fs.open(this.lockPath, "wx", 0o640);
        await handle.writeFile(JSON.stringify({
          pid: process.pid,
          createdAt: new Date().toISOString()
        }));
        await handle.sync();
        return handle;
      } catch (error) {
        if (error?.code !== "EEXIST") throw error;
        try {
          const stat = await fs.stat(this.lockPath);
          if (Date.now() - stat.mtimeMs > staleMs) {
            await fs.unlink(this.lockPath);
            continue;
          }
        } catch (statError) {
          if (statError?.code !== "ENOENT") throw statError;
        }
        await sleep(50);
      }
    }

    throw new Error(`Timed out waiting for job-store lock: ${this.lockPath}`);
  }

  async releaseLock(handle) {
    try { await handle?.close(); } finally {
      try { await fs.unlink(this.lockPath); }
      catch (error) { if (error?.code !== "ENOENT") throw error; }
    }
  }

  async writeAllUnlocked(jobs) {
    if (!Array.isArray(jobs)) throw new Error("Refusing to write a non-array job store.");
    await fs.mkdir(path.dirname(this.filePath), { recursive: true });
    const temp = `${this.filePath}.tmp.${process.pid}.${crypto.randomUUID()}`;
    let handle;
    try {
      handle = await fs.open(temp, "wx", 0o640);
      await handle.writeFile(JSON.stringify(jobs, null, 2) + "\n", "utf8");
      await handle.sync();
      await handle.close();
      handle = null;
      JSON.parse(await fs.readFile(temp, "utf8"));
      await fs.rename(temp, this.filePath);
    } finally {
      try { await handle?.close(); } catch {}
      try { await fs.unlink(temp); } catch (error) {
        if (error?.code !== "ENOENT") throw error;
      }
    }
  }

  async writeAll(jobs) {
    const lock = await this.acquireLock();
    try { await this.writeAllUnlocked(jobs); }
    finally { await this.releaseLock(lock); }
  }

  async mutate(mutator) {
    const lock = await this.acquireLock();
    try {
      const jobs = await this.readAll();
      const result = await mutator(jobs);
      await this.writeAllUnlocked(jobs);
      return result;
    } finally {
      await this.releaseLock(lock);
    }
  }

  async create(job) {
    return this.mutate(async jobs => {
      jobs.push(job);
      return job;
    });
  }

  async get(id) {
    return (await this.readAll()).find(job => job.id === id) ?? null;
  }

  async update(id, patch) {
    return this.mutate(async jobs => {
      const index = jobs.findIndex(job => job.id === id);
      if (index < 0) throw new Error(`Job not found: ${id}`);
      jobs[index] = { ...jobs[index], ...patch, updatedAt: new Date().toISOString() };
      return jobs[index];
    });
  }

  async nextQueued() {
    return (await this.readAll())
      .filter(job => job.status === "queued")
      .sort((a, b) => b.priority - a.priority || a.createdAt.localeCompare(b.createdAt))[0] ?? null;
  }

  async list() {
    return this.readAll();
  }
}
