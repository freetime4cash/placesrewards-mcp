import { createRuntime, summarizeJobs } from "./lib/runtime.js";
const { orchestrator } = createRuntime();
const jobs = await orchestrator.listJobs();
console.log(JSON.stringify({
  status: summarizeJobs(jobs),
  waiting: jobs.filter(j => j.status === "waiting_approval").map(j => ({ id: j.id, agent: j.agent, objective: j.objective, result: j.result }))
}, null, 2));
