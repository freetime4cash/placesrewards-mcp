import { createRuntime, summarizeJobs } from "./lib/runtime.js";
const { orchestrator } = createRuntime();
const processed = await orchestrator.runUntilIdle();
const jobs = await orchestrator.listJobs();
console.log(JSON.stringify({ processed: processed.length, status: summarizeJobs(jobs) }, null, 2));
