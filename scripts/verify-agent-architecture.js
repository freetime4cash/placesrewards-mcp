import assert from "node:assert/strict";
import { createRuntime } from "../lib/runtime.js";
import { routeObjective } from "../lib/specialist-agents.js";
import { SKILLS } from "../lib/skills.js";

process.env.PLACESREWARDS_AGENT_JOB_FILE = process.env.PLACESREWARDS_AGENT_JOB_FILE ?? "/tmp/placesrewards-agent-architecture-test.json";

const { orchestrator } = createRuntime();
const agents = orchestrator.listAgents();
const names = new Set(agents.map(a => a.name));

for (const required of [
  "command","build","test","demo","architecture_guardian","revenue_architect","monetization_controller",
  "revenue_leak","campaign_architect","mystery_engine","growth","local_intelligence","sales","demo_factory",
  "treasure_hunt","three63","rewards_intelligence","network","automation","analytics_roi","backend_engineer",
  "frontend_product","integration","qa_adversarial","security","deployment","documentation","optimization",
  "opportunity_discovery","business_twin","simulation"
]) assert(names.has(required), `Missing agent: ${required}`);

assert(Object.keys(SKILLS).length >= 30, "Skill registry is unexpectedly small");

const treasure = routeObjective("Convert Northeast Ohio Treasure Hunt businesses into subscriptions");
for (const required of ["monetization_controller","treasure_hunt","campaign_architect","sales","revenue_architect","analytics_roi"])
  assert(treasure.includes(required), `Treasure Hunt routing missing ${required}`);

const three63 = routeObjective("Build a 363 Foundation and Radio Cigars mystery campaign");
for (const required of ["monetization_controller","three63","campaign_architect","mystery_engine","revenue_architect","demo_factory"])
  assert(three63.includes(required), `363 routing missing ${required}`);

const code = routeObjective("Implement Laravel API integration");
for (const required of ["architecture_guardian","security","qa_adversarial"])
  assert(code.includes(required), `Code routing missing ${required}`);

console.log(JSON.stringify({ ok: true, agents: agents.length, skills: Object.keys(SKILLS).length, treasure, three63, code }, null, 2));
