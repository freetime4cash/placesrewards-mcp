import { createRuntime } from "./lib/runtime.js";
const id = process.argv[2];
if (!id) { console.error("Usage: node approve.js <JOB-ID>"); process.exit(1); }
const { orchestrator } = createRuntime();
console.log(JSON.stringify(await orchestrator.approveJob(id, 3), null, 2));
