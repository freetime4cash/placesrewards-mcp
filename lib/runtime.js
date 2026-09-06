import path from "node:path";
import { FileJobStore } from "./store.js";
import { AgentOrchestrator } from "./orchestrator.js";
import { CommandAgent, BuildAgent, TestAgent, DemoAgent } from "./agents.js";
import { createSpecialistAgents } from "./specialist-agents.js";
import { RevenueLeakExecutionAgent } from "./revenue-agent.js";

export function createRuntime() {
  const jobFile = process.env.PLACESREWARDS_AGENT_JOB_FILE ?? path.resolve("data","agent-jobs.json");
  const store = new FileJobStore(jobFile);
  const orchestrator = new AgentOrchestrator(store);

  orchestrator.register(new CommandAgent());
  orchestrator.register(new BuildAgent());
  orchestrator.register(new TestAgent());
  orchestrator.register(new DemoAgent());
  for (const agent of createSpecialistAgents()) orchestrator.register(agent);

  // Replace the planning-only revenue_leak specialist with the executable
  // diagnostic agent while preserving the same routing name.
  orchestrator.register(new RevenueLeakExecutionAgent());

  return { store, orchestrator, jobFile };
}

export function summarizeJobs(jobs) {
  return {
    total: jobs.length,
    queued: jobs.filter(j => j.status === "queued").length,
    running: jobs.filter(j => j.status === "running").length,
    completed: jobs.filter(j => j.status === "completed").length,
    failed: jobs.filter(j => j.status === "failed").length,
    waitingApproval: jobs.filter(j => j.status === "waiting_approval").length
  };
}
