import { diagnoseRevenueLeaks, rankRevenueOpportunities, buildRecoveryPlan } from "./revenue-engine.js";
import { entitlementForTier, requireRevenueCapability } from "./revenue-entitlements.js";

export class RevenueLeakExecutionAgent {
  name = "revenue_leak";
  mission = "Detect, quantify, rank and prescribe recovery for merchant revenue leaks.";
  maxAutonomy = 2;
  skills = ["revenue_leak_detection", "customer_behavior", "merchant_roi", "campaign_generation", "attribution", "opportunity_scoring"];

  async run(context) {
    const input = context.input ?? {};
    const businesses = Array.isArray(input.businesses)
      ? input.businesses
      : Array.isArray(input.businessProfiles)
        ? input.businessProfiles
        : input.business && typeof input.business === "object"
          ? [input.business]
          : [];

    if (!businesses.length) {
      return {
        ok: true,
        summary: `Revenue Leak Agent is ready but no normalized business signals were supplied for: ${context.objective}`,
        data: {
          agent: this.name,
          requiredInput: {
            business: "object or businesses[]",
            recommendedSignals: [
              "average_ticket",
              "missed_calls_monthly",
              "call_conversion_rate",
              "dormant_customers",
              "reactivation_rate",
              "repeat_purchase_gap_monthly",
              "uncontacted_leads_monthly",
              "lead_conversion_rate",
              "no_shows_monthly",
              "no_show_recovery_rate"
            ]
          },
          actions: [{ name: "revenue_signal_requirements", risk: "read", details: { execute: true } }]
        }
      };
    }

    const tier = String(input.revenueEngineTier ?? "growth");
    const entitlement = entitlementForTier(tier);
    requireRevenueCapability(entitlement, "diagnostics");

    const reports = businesses.map(diagnoseRevenueLeaks);
    const recoveryPlans = reports.map(buildRecoveryPlan);
    let ranked = reports.map((report, index) => ({
      business: businesses[index],
      report,
      opportunityScore: null,
      estimatedMonthlyLoss: report.totalEstimatedMonthlyLoss,
      estimatedAnnualLoss: report.totalEstimatedAnnualLoss,
      leakCount: report.leaks.length,
      topLeak: report.leaks[0]?.title ?? null
    }));

    if (entitlement.capabilities.automatedDiscovery) ranked = rankRevenueOpportunities(businesses);
    else ranked.sort((a, b) => b.estimatedMonthlyLoss - a.estimatedMonthlyLoss);

    const monthlyOpportunity = reports.reduce((sum, report) => sum + report.totalEstimatedMonthlyLoss, 0);
    const annualOpportunity = reports.reduce((sum, report) => sum + report.totalEstimatedAnnualLoss, 0);

    return {
      ok: true,
      summary: `Revenue Leak Agent diagnosed ${businesses.length} business profile(s) with $${monthlyOpportunity.toFixed(2)} modeled monthly opportunity.`,
      data: {
        entitlement,
        reports,
        ranked,
        recoveryPlans,
        portfolio: {
          businessesDiagnosed: businesses.length,
          modeledMonthlyOpportunity: Math.round(monthlyOpportunity * 100) / 100,
          modeledAnnualOpportunity: Math.round(annualOpportunity * 100) / 100
        },
        actions: [{ name: "diagnose_and_rank_revenue_leaks", risk: "read", details: { execute: true } }]
      }
    };
  }
}
