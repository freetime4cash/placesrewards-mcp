import { randomUUID } from "node:crypto";
import { evaluateAction } from "./policy.js";

const VALID_RISKS = new Set(["read","safe_write","protected_write","destructive"]);

export class AgentOrchestrator {
  constructor(store) { this.store = store; this.agents = new Map(); }
  register(agent) { this.agents.set(agent.name, agent); }

  async enqueue({ agent, objective, input, priority = 50, autonomyLevel = 2, maxAttempts = 3 }) {
    const now = new Date().toISOString();
    const job = {
      id: randomUUID(),
      agent,
      objective,
      status: "queued",
      priority,
      autonomyLevel,
      attempts: 0,
      maxAttempts,
      approval: null,
      createdAt: now,
      updatedAt: now,
      ...(input !== undefined ? { input } : {})
    };
    await this.store.create(job);
    return job;
  }

  tasks(data) {
    if (!data || !Array.isArray(data.tasks)) return [];
    return data.tasks.filter(t => t && typeof t === "object" && this.agents.has(t.agent) && typeof t.objective === "string");
  }

  actions(data) {
    if (!data || !Array.isArray(data.actions)) return [];
    return data.actions.filter(a => a && typeof a === "object" && typeof a.name === "string" && VALID_RISKS.has(a.risk));
  }

  async runNext() {
    const job = await this.store.nextQueued();
    if (!job) return null;

    const agent = this.agents.get(job.agent);
    if (!agent) return this.store.update(job.id, { status: "failed", error: `No agent registered for ${job.agent}`, currentStep: "Agent registration failed" });

    if (job.autonomyLevel > agent.maxAutonomy) {
      return this.store.update(job.id, { status: "waiting_approval", error: `Requested autonomy ${job.autonomyLevel} exceeds ${agent.name} maximum ${agent.maxAutonomy}`, currentStep: "Waiting for approval" });
    }

    const running = await this.store.update(job.id, { status: "running", attempts: job.attempts + 1, currentStep: "Agent execution started" });

    try {
      const result = await agent.run({
        jobId: running.id,
        objective: running.objective,
        input: running.input ?? {},
        autonomyLevel: running.autonomyLevel,
        approval: running.approval ?? null
      });
      const decisions = this.actions(result.data).map(action => ({ action, decision: evaluateAction(action.risk, running.autonomyLevel) }));

      if (result.requiresApproval || decisions.some(d => d.decision.requiresApproval)) {
        return this.store.update(job.id, {
          status: "waiting_approval",
          currentStep: "Action approval required",
          result: { summary: result.summary, ...(result.data ?? {}), ...(decisions.length ? { actionDecisions: decisions } : {}) }
        });
      }

      const delegatedJobIds = [];
      for (const task of this.tasks(result.data)) {
        const queued = await this.enqueue({ agent: task.agent, objective: task.objective, priority: task.priority ?? 50, autonomyLevel: task.autonomyLevel ?? 2, input: task.input });
        delegatedJobIds.push(queued.id);
      }

      return this.store.update(job.id, {
        status: result.ok ? "completed" : "failed",
        currentStep: result.ok ? "Completed" : "Failed",
        result: { summary: result.summary, ...(result.data ?? {}), ...(decisions.length ? { actionDecisions: decisions } : {}), ...(delegatedJobIds.length ? { delegatedJobIds } : {}) }
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      const latest = await this.store.get(job.id);
      const attempts = latest?.attempts ?? running.attempts;

      if (attempts < job.maxAttempts) {
        return this.store.update(job.id, { status: "queued", error: message, currentStep: `Retry scheduled after attempt ${attempts}` });
      }

      return this.store.update(job.id, { status: "failed", error: message, currentStep: "Maximum retries reached" });
    }
  }

  async runUntilIdle(maxJobs = 50) {
    const out = [];
    for (let i = 0; i < maxJobs; i += 1) {
      const job = await this.runNext();
      if (!job) break;
      out.push(job);
    }
    return out;
  }

  async approveJob(id, approvedAutonomyLevel = 3) {
    const job = await this.store.get(id);
    if (!job) throw new Error(`Job not found: ${id}`);
    if (job.status !== "waiting_approval") throw new Error(`Job ${id} is not waiting for approval.`);
    const approvedAt = new Date().toISOString();
    return this.store.update(id, {
      status: "queued",
      autonomyLevel: approvedAutonomyLevel,
      approval: {
        approvedAt,
        approvedAutonomyLevel,
        source: "explicit_job_approval"
      },
      currentStep: "Approved and returned to queue"
    });
  }

  listJobs() { return this.store.list(); }
  listAgents() { return [...this.agents.values()].map(agent => ({ name: agent.name, maxAutonomy: agent.maxAutonomy, mission: agent.mission ?? null, skills: agent.skills ?? [] })); }
}
