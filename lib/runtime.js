import path from "node:path";
import { FileJobStore } from "./store.js";
import { AgentOrchestrator } from "./orchestrator.js";
import { CommandAgent, BuildAgent, TestAgent, DemoAgent } from "./agents.js";
import { createSpecialistAgents } from "./specialist-agents.js";
import { RevenueLeakExecutionAgent } from "./revenue-agent.js";
import { SalesExecutionAgent, CampaignExecutionAgent, DemoFactoryExecutionAgent } from "./commercial-agents.js";

export function createRuntime() {
  const jobFile = process.env.PLACESREWARDS_AGENT_JOB_FILE ?? path.resolve("data","agent-jobs.json");
  const store = new FileJobStore(jobFile);
  const orchestrator = new AgentOrchestrator(store);

  orchestrator.register(new CommandAgent());
  orchestrator.register(new BuildAgent());
  orchestrator.register(new TestAgent());
  orchestrator.register(new DemoAgent());
  for (const agent of createSpecialistAgents()) orchestrator.register(agent);

  // Replace planning-only specialists with executable agents while preserving
  // the same routing names used throughout the autonomous control plane.
  orchestrator.register(new RevenueLeakExecutionAgent());
  orchestrator.register(new SalesExecutionAgent());
  orchestrator.register(new CampaignExecutionAgent());
  orchestrator.register(new DemoFactoryExecutionAgent());

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
