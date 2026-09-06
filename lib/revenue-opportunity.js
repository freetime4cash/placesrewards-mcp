import { diagnoseRevenueLeaks, scoreRevenueOpportunity, buildRecoveryPlan } from "./revenue-engine.js";
import { entitlementForTier, requireRevenueCapability } from "./revenue-entitlements.js";

export const REVENUE_OPPORTUNITY_STAGES = Object.freeze([
  "discovered",
  "diagnosed",
  "quantified",
  "prescribed",
  "demonstrated",
  "closed",
  "recovering",
  "measured"
]);

const nowIso = () => new Date().toISOString();
const money = value => Math.round((Number(value) || 0) * 100) / 100;

function assertStage(opportunity, expected) {
  if (opportunity.stage !== expected) throw new Error(`Opportunity '${opportunity.id}' is at stage '${opportunity.stage}', expected '${expected}'.`);
}

function transition(opportunity, stage, payload = {}) {
  const at = nowIso();
  return {
    ...opportunity,
    ...payload,
    stage,
    updatedAt: at,
    history: [...(opportunity.history ?? []), { stage, at }]
  };
}

export function createRevenueOpportunity(business, { tier = "pro", source = "manual" } = {}) {
  const createdAt = nowIso();
  const businessId = String(business?.id ?? business?.name ?? "unknown-business");
  const entitlement = entitlementForTier(tier);
  requireRevenueCapability(entitlement, "diagnostics");
  return {
    id: `opp:${businessId}:${createdAt}`,
    business,
    source,
    tier,
    entitlement,
    stage: "discovered",
    score: null,
    createdAt,
    updatedAt: createdAt,
    history: [{ stage: "discovered", at: createdAt }],
    report: null,
    quantified: null,
    recoveryPlan: null,
    demonstration: null,
    close: null,
    recovery: null,
    measurement: null
  };
}

export function diagnoseRevenueOpportunity(opportunity) {
  assertStage(opportunity, "discovered");
  const report = diagnoseRevenueLeaks(opportunity.business);
  return transition(opportunity, "diagnosed", { report, score: scoreRevenueOpportunity(report) });
}

export function quantifyRevenueOpportunity(opportunity) {
  assertStage(opportunity, "diagnosed");
  const report = opportunity.report;
  const confidenceWeightedMonthly = money(report.leaks.reduce((sum, item) => sum + item.estimatedMonthlyLoss * item.confidence, 0));
  return transition(opportunity, "quantified", {
    quantified: {
      modeledMonthlyLoss: report.totalEstimatedMonthlyLoss,
      modeledAnnualLoss: report.totalEstimatedAnnualLoss,
      confidenceWeightedMonthlyOpportunity: confidenceWeightedMonthly,
      confidenceWeightedAnnualOpportunity: money(confidenceWeightedMonthly * 12),
      leakCount: report.leaks.length,
      opportunityScore: scoreRevenueOpportunity(report)
    }
  });
}

export function prescribeRevenueOpportunity(opportunity) {
  assertStage(opportunity, "quantified");
  return transition(opportunity, "prescribed", { recoveryPlan: buildRecoveryPlan(opportunity.report) });
}

export function demonstrateRevenueOpportunity(opportunity) {
  assertStage(opportunity, "prescribed");
  const report = opportunity.report;
  return transition(opportunity, "demonstrated", {
    demonstration: {
      headline: `Modeled monthly revenue opportunity: $${report.totalEstimatedMonthlyLoss.toLocaleString()}`,
      annualOpportunity: report.totalEstimatedAnnualLoss,
      proofPoints: report.leaks.map(item => ({
        category: item.category,
        evidence: item.evidence,
        confidence: item.confidence,
        monthlyLoss: item.estimatedMonthlyLoss,
        recommendedFix: item.recommendedFix,
        placesRewardsModule: item.placesRewardsModule ?? null
      })),
      disclaimer: "Opportunity estimates are modeled from available evidence and should be validated with merchant-owned data before any claim of recovered revenue."
    }
  });
}

export function runRevenueDiagnosticWorkflow(business, options = {}) {
  let opportunity = createRevenueOpportunity(business, options);
  opportunity = diagnoseRevenueOpportunity(opportunity);
  opportunity = quantifyRevenueOpportunity(opportunity);
  opportunity = prescribeRevenueOpportunity(opportunity);
  opportunity = demonstrateRevenueOpportunity(opportunity);
  return opportunity;
}

export function closeRevenueOpportunity(opportunity, close = {}) {
  assertStage(opportunity, "demonstrated");
  const status = close.status ?? "won";
  if (!["won", "lost", "deferred"].includes(status)) throw new Error(`Unsupported close status '${status}'.`);
  return transition(opportunity, "closed", {
    close: {
      status,
      agreedMonthlyFee: money(close.agreedMonthlyFee),
      setupFee: money(close.setupFee),
      recoverySharePercent: money(close.recoverySharePercent),
      notes: close.notes ?? "",
      closedAt: nowIso()
    }
  });
}

export function beginRevenueRecovery(opportunity, { approvedCategories = [] } = {}) {
  assertStage(opportunity, "closed");
  if (opportunity.close?.status !== "won") throw new Error(`Recovery cannot start for a '${opportunity.close?.status}' opportunity.`);
  const approved = new Set(approvedCategories);
  const actions = (opportunity.recoveryPlan?.actions ?? []).map(action => ({
    ...action,
    approved: approved.has(action.category),
    status: approved.has(action.category) ? "approved" : "pending_approval"
  }));
  return transition(opportunity, "recovering", {
    recovery: {
      startedAt: nowIso(),
      actions,
      approvedActionCount: actions.filter(action => action.approved).length
    }
  });
}

export function measureRevenueOpportunity(opportunity, currentBusiness) {
  assertStage(opportunity, "recovering");
  const currentReport = diagnoseRevenueLeaks(currentBusiness);
  const previous = opportunity.report.totalEstimatedMonthlyLoss;
  const current = currentReport.totalEstimatedMonthlyLoss;
  const delta = money(current - previous);
  const recoveredEstimate = delta < 0 ? Math.abs(delta) : 0;
  return transition(opportunity, "measured", {
    measurement: {
      measuredAt: nowIso(),
      currentReport,
      comparison: {
        previous,
        current,
        delta,
        improved: delta < 0,
        recoveredEstimate,
        regressedEstimate: delta > 0 ? delta : 0
      },
      recoveredMonthlyEstimate: money(recoveredEstimate),
      recoveredAnnualizedEstimate: money(recoveredEstimate * 12),
      claimStatus: recoveredEstimate > 0 ? "modeled-improvement" : "no-modeled-improvement"
    }
  });
}

export function serializeRevenueOpportunity(opportunity) {
  return JSON.parse(JSON.stringify(opportunity));
}
