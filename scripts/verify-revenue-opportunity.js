import assert from "node:assert/strict";
import {
  runRevenueDiagnosticWorkflow,
  closeRevenueOpportunity,
  beginRevenueRecovery,
  measureRevenueOpportunity
} from "../lib/revenue-opportunity.js";

const baseline = {
  id: "merchant-1",
  name: "Merchant One",
  averageTicket: 100,
  metrics: {
    missed_calls_monthly: 20,
    call_conversion_rate: 0.5,
    dormant_customers: 50,
    reactivation_rate: 0.1
  }
};

let opportunity = runRevenueDiagnosticWorkflow(baseline, { tier: "pro", source: "test" });
assert.equal(opportunity.stage, "demonstrated");
assert.ok(opportunity.score > 0);
assert.equal(opportunity.quantified.leakCount, 2);
assert.ok(opportunity.demonstration.proofPoints.length === 2);

opportunity = closeRevenueOpportunity(opportunity, { status: "won", agreedMonthlyFee: 79, setupFee: 99 });
assert.equal(opportunity.stage, "closed");
assert.equal(opportunity.close.agreedMonthlyFee, 79);

opportunity = beginRevenueRecovery(opportunity, { approvedCategories: ["missed_call"] });
assert.equal(opportunity.stage, "recovering");
assert.equal(opportunity.recovery.approvedActionCount, 1);

const improved = {
  ...baseline,
  metrics: {
    ...baseline.metrics,
    missed_calls_monthly: 5,
    dormant_customers: 20
  }
};

opportunity = measureRevenueOpportunity(opportunity, improved);
assert.equal(opportunity.stage, "measured");
assert.equal(opportunity.measurement.comparison.improved, true);
assert.ok(opportunity.measurement.recoveredMonthlyEstimate > 0);

console.log(JSON.stringify({
  ok: true,
  stage: opportunity.stage,
  recoveredMonthlyEstimate: opportunity.measurement.recoveredMonthlyEstimate,
  recoveredAnnualizedEstimate: opportunity.measurement.recoveredAnnualizedEstimate
}, null, 2));
