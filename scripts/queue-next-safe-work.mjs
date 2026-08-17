import { createRuntime } from "/home/placevle/placesrewards-agent-server/lib/runtime.js";
import { promises as fs } from "node:fs";

const AGENT_ROOT =
  process.env.PLACESREWARDS_AGENT_ROOT ??
  "/home/placevle/placesrewards-agent-server";

const backlogFile = `${AGENT_ROOT}/data/autonomous-backlog.json`;

const backlog = JSON.parse(
  await fs.readFile(backlogFile, "utf8")
);

if (!Array.isArray(backlog) || backlog.length === 0) {
  console.log("No backlog work to queue.");
  process.exit(0);
}

const next = backlog[0];

const { orchestrator } = createRuntime();

const job = await orchestrator.enqueue({
  agent: "build",
  objective:
    `Autonomous intake priority ${next.priority}: ${next.title}. ` +
    `${next.reason} Inspect first. Do not modify production without explicit approval.`,
  priority: next.priority,
  autonomyLevel: 2,
  input: {
    backlogItem: next,
    operation: "inspect"
  }
});

console.log(JSON.stringify({
  queued: true,
  jobId: job.id,
  priority: next.priority,
  title: next.title,
  productionWriteAllowedAutomatically: false
}, null, 2));
