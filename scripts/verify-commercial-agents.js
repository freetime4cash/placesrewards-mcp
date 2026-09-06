import assert from "node:assert/strict";
import { SalesExecutionAgent, CampaignExecutionAgent, DemoFactoryExecutionAgent } from "../lib/commercial-agents.js";

const internalOpportunity = {
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

const publicOpportunity = {
  score: 74,
  evidenceQuality: { score: 70 },
  source: "public-prospect-intake",
  recommendedEntryOffer: "Repeat-Visit Revenue Engine",
  business: {
    id: "public:example-salon",
    name: "Example Salon",
    signals: [
      { key: "public_loyalty_gap", value: true, source: "https://example.com", observedAt: new Date().toISOString(), evidence: "https://example.com" }
    ]
  },
  report: {
    businessId: "public:example-salon",
    businessName: "Example Salon",
    totalEstimatedMonthlyLoss: 0,
    totalEstimatedAnnualLoss: 0,
    monetaryClaimAvailable: false,
    leaks: [
      {
        category: "loyalty_gap",
        title: "No visible repeat-visit loyalty mechanic was found in the public customer journey",
        estimatedMonthlyLoss: 0,
        confidence: 0.72,
        recommendedFix: "Demonstrate a measurable repeat-visit loyalty workflow.",
        placesRewardsModule: "loyalty/stamp-cards",
        evidence: ["No visible loyalty program on reviewed public page"]
      }
    ]
  },
  publicEvidence: [{ url: "https://example.com", observedFact: "No visible loyalty program on reviewed public page" }]
};

const salesAgent = new SalesExecutionAgent();
const campaignAgent = new CampaignExecutionAgent();
const demoAgent = new DemoFactoryExecutionAgent();

const sales = await salesAgent.run({ input: { opportunity: internalOpportunity }, objective: "Convert merchant" });
const campaign = await campaignAgent.run({ input: { opportunity: internalOpportunity }, objective: "Build campaign" });
const demo = await demoAgent.run({ input: { opportunity: internalOpportunity }, objective: "Build demo" });

assert.equal(sales.ok, true);
assert.equal(sales.data.qualification, "qualified");
assert.equal(sales.data.offer.name, "Dormant Customer Reactivation Sprint");
assert.equal(sales.data.nextBestAction, "prepare_outreach_draft_and_personalized_demo_packet");
assert.equal(sales.data.openingProof.monetaryClaimAvailable, true);
assert.equal(campaign.ok, true);
assert.equal(campaign.data.campaign.measurementWindowDays, 30);
assert.equal(campaign.data.campaign.successThreshold.type, "validation_target_not_guarantee");
assert.equal(demo.ok, true);
assert.equal(demo.data.demo.productionCreationAllowed, false);
assert.match(demo.data.demo.claimsPolicy, /modeled opportunity as guaranteed revenue/);

const publicSales = await salesAgent.run({ input: { opportunity: publicOpportunity }, objective: "Convert public prospect" });
const publicCampaign = await campaignAgent.run({ input: { opportunity: publicOpportunity }, objective: "Build public prospect pilot" });
const publicDemo = await demoAgent.run({ input: { opportunity: publicOpportunity }, objective: "Build public prospect demo" });

assert.equal(publicSales.data.qualification, "qualified");
assert.equal(publicSales.data.offer.name, "Repeat-Visit Revenue Engine");
assert.equal(publicSales.data.openingProof.monetaryClaimAvailable, false);
assert.equal(publicSales.data.openingProof.modeledMonthlyOpportunity, 0);
assert.match(publicSales.data.openingProof.disclaimer, /No monetary loss is claimed/);
assert.equal(publicCampaign.data.campaign.successThreshold.type, "baseline_improvement_target");
assert.equal(publicCampaign.data.campaign.successThreshold.recoveredValueTarget, null);
assert.equal(publicDemo.data.demo.productionCreationAllowed, false);
assert.match(publicDemo.data.conversionNarrative, /Public evidence identifies a testable opportunity/);

console.log(JSON.stringify({ ok: true, agents: ["sales", "campaign_architect", "demo_factory"], publicProspectGuardrails: true }, null, 2));
