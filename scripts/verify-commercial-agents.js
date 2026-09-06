import assert from "node:assert/strict";
import { SalesExecutionAgent, CampaignExecutionAgent, DemoFactoryExecutionAgent } from "../lib/commercial-agents.js";

const opportunity = {
  score: 78,
  evidenceQuality: { score: 82 },
  business: {
    id: "merchant-1",
    name: "Example Local Business",
    signals: [
      { key: "average_ticket", value: 75, source: "test", observedAt: new Date().toISOString(), evidence: "analytics" },
      { key: "dormant_customers", value: 100, source: "test", observedAt: new Date().toISOString(), evidence: "analytics" }
    ]
  },
  report: {
    businessId: "merchant-1",
    businessName: "Example Local Business",
    totalEstimatedMonthlyLoss: 600,
    totalEstimatedAnnualLoss: 7200,
    leaks: [
      {
        category: "reactivation",
        title: "Dormant customers represent recoverable revenue",
        estimatedMonthlyLoss: 600,
        confidence: 0.82,
        recommendedFix: "Launch segmented win-back offers and automated reactivation sequences.",
        placesRewardsModule: "offers/campaigns",
        evidence: ["100 dormant customers", "Modeled recoverable rate: 8.0%"]
      }
    ]
  }
};

const input = { opportunity };
const sales = await new SalesExecutionAgent().run({ input, objective: "Convert merchant" });
const campaign = await new CampaignExecutionAgent().run({ input, objective: "Build campaign" });
const demo = await new DemoFactoryExecutionAgent().run({ input, objective: "Build demo" });

assert.equal(sales.ok, true);
assert.equal(sales.data.qualification, "qualified");
assert.equal(sales.data.offer.name, "Dormant Customer Reactivation Sprint");
assert.equal(sales.data.nextBestAction, "prepare_outreach_draft_and_personalized_demo_packet");
assert.equal(campaign.ok, true);
assert.equal(campaign.data.campaign.measurementWindowDays, 30);
assert.equal(campaign.data.campaign.successThreshold.type, "validation_target_not_guarantee");
assert.equal(demo.ok, true);
assert.equal(demo.data.demo.productionCreationAllowed, false);
assert.match(demo.data.demo.claimsPolicy, /Never present modeled opportunity as guaranteed revenue/);

console.log(JSON.stringify({ ok: true, agents: ["sales", "campaign_architect", "demo_factory"] }, null, 2));
