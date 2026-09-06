import assert from "node:assert/strict";
import { diagnoseRevenueLeaks, rankRevenueOpportunities, buildRecoveryPlan } from "../lib/revenue-engine.js";
import { entitlementForTier, requireRevenueCapability } from "../lib/revenue-entitlements.js";

const business = {
  id: "test-business",
  name: "Test Business",
  averageTicket: 100,
  metrics: {
    missed_calls_monthly: 20,
    call_conversion_rate: 0.5,
    dormant_customers: 100,
    reactivation_rate: 0.1,
    repeat_purchase_gap_monthly: 5,
    uncontacted_leads_monthly: 10,
    lead_conversion_rate: 0.2,
    no_shows_monthly: 4,
    no_show_recovery_rate: 0.5
  }
};

const report = diagnoseRevenueLeaks(business);
assert.equal(report.businessId, "test-business");
assert.equal(report.leaks.length, 5);
assert.equal(report.totalEstimatedMonthlyLoss, 2900);
assert.equal(report.totalEstimatedAnnualLoss, 34800);
assert.equal(report.leaks[0].category, "missed_call");

const ranked = rankRevenueOpportunities([
  business,
  { id: "smaller", name: "Smaller", averageTicket: 50, metrics: { missed_calls_monthly: 2, call_conversion_rate: 0.5 } }
]);
assert.equal(ranked.length, 2);
assert.equal(ranked[0].business.id, "test-business");
assert.ok(ranked[0].opportunityScore > ranked[1].opportunityScore);

const plan = buildRecoveryPlan(report);
assert.equal(plan.actions.length, 5);
assert.equal(plan.actions[0].risk, "safe_write");
assert.equal(plan.measurement.baselineRequired, true);

const growth = entitlementForTier("growth");
assert.equal(growth.capabilities.diagnostics, true);
assert.equal(growth.capabilities.automatedDiscovery, false);
assert.throws(() => requireRevenueCapability(growth, "automatedDiscovery"));
assert.equal(requireRevenueCapability(entitlementForTier("pro"), "automatedDiscovery"), true);
assert.equal(requireRevenueCapability(entitlementForTier("enterprise"), "recoveryAutomation"), true);

console.log(JSON.stringify({ ok: true, monthlyOpportunity: report.totalEstimatedMonthlyLoss, annualOpportunity: report.totalEstimatedAnnualLoss, leakCount: report.leaks.length }, null, 2));
