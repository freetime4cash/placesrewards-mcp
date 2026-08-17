import { promises as fs } from "node:fs";
import path from "node:path";

export class FileJobStore {
  constructor(filePath) { this.filePath = filePath; }
  async readAll() {
    try { return JSON.parse(await fs.readFile(this.filePath, "utf8")); }
    catch (error) { if (error?.code === "ENOENT") return []; throw error; }
  }
  async writeAll(jobs) {
    await fs.mkdir(path.dirname(this.filePath), { recursive: true });
    const temp = `${this.filePath}.tmp`;
    await fs.writeFile(temp, JSON.stringify(jobs, null, 2), "utf8");
    await fs.rename(temp, this.filePath);
  }
  async create(job) { const jobs = await this.readAll(); jobs.push(job); await this.writeAll(jobs); }
  async get(id) { return (await this.readAll()).find(j => j.id === id) ?? null; }
  async update(id, patch) {
    const jobs = await this.readAll();
    const i = jobs.findIndex(j => j.id === id);
    if (i < 0) throw new Error(`Job not found: ${id}`);
    jobs[i] = { ...jobs[i], ...patch, updatedAt: new Date().toISOString() };
    await this.writeAll(jobs);
    return jobs[i];
  }
  async nextQueued() {
    return (await this.readAll()).filter(j => j.status === "queued")
      .sort((a,b) => b.priority - a.priority || a.createdAt.localeCompare(b.createdAt))[0] ?? null;
  }
  async list() { return this.readAll(); }
}
