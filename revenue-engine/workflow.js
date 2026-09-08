import {
  RevenueEngineService,
  entitlementFor,
  opportunityScore,
  buildRecoveryPlan,
  buildExecutiveReport,
  compareSnapshots,
  normalizeBusiness,
  diagnose,
} from './index.js';

export const OPPORTUNITY_STAGES = Object.freeze([
  'discovered',
  'diagnosed',
  'quantified',
  'prescribed',
  'demonstrated',
  'closed',
  'recovering',
  'measured',
]);

const nowIso = () => new Date().toISOString();
const money = n => Math.round((Number(n) || 0) * 100) / 100;

function assertStage(opportunity, expected) {
  if (opportunity.stage !== expected) {
    throw new Error(`Opportunity '${opportunity.id}' is at stage '${opportunity.stage}', expected '${expected}'.`);
  }
}

function transition(opportunity, stage, payload = {}) {
  const at = nowIso();
  return {
    ...opportunity,
    ...payload,
    stage,
    updatedAt: at,
    history: [...(opportunity.history || []), { stage, at }],
  };
}

export function createOpportunity(record, { tier = 'pro', source = 'manual' } = {}) {
  const engine = new RevenueEngineService({ tier });
  const business = normalizeBusiness(record);
  const report = diagnose(business);
  const score = opportunityScore(report);
  const createdAt = nowIso();

  return {
    id: `opp:${business.id}:${createdAt}`,
    business,
    source,
    tier,
    entitlement: entitlementFor(tier),
    stage: 'discovered',
    score,
    createdAt,
    updatedAt: createdAt,
    history: [{ stage: 'discovered', at: createdAt }],
    report: null,
    quantified: null,
    recoveryPlan: null,
    demonstration: null,
    close: null,
    recovery: null,
    measurement: null,
    _discoveryReport: report,
    _engine: engine,
  };
}

export function diagnoseOpportunity(opportunity) {
  assertStage(opportunity, 'discovered');
  const report = opportunity._discoveryReport || diagnose(opportunity.business);
  return transition(opportunity, 'diagnosed', { report });
}

export function quantifyOpportunity(opportunity) {
  assertStage(opportunity, 'diagnosed');
  const report = opportunity.report;
  const confidenceWeightedMonthly = money(
    report.leaks.reduce((sum, leak) => sum + leak.estimatedMonthlyLoss * leak.confidence, 0)
  );
  const quantified = {
    modeledMonthlyLoss: report.totalEstimatedMonthlyLoss,
    modeledAnnualLoss: report.totalEstimatedAnnualLoss,
    confidenceWeightedMonthlyOpportunity: confidenceWeightedMonthly,
    confidenceWeightedAnnualOpportunity: money(confidenceWeightedMonthly * 12),
    leakCount: report.leaks.length,
    opportunityScore: opportunityScore(report),
  };
  return transition(opportunity, 'quantified', { quantified });
}

export function prescribeOpportunity(opportunity) {
  assertStage(opportunity, 'quantified');
  const recoveryPlan = buildRecoveryPlan(opportunity.report, opportunity.entitlement);
  return transition(opportunity, 'prescribed', { recoveryPlan });
}

export function demonstrateOpportunity(opportunity) {
  assertStage(opportunity, 'prescribed');
  const canBuildExecutiveReport = Boolean(opportunity.entitlement.capabilities.advancedReporting);
  const executiveReport = canBuildExecutiveReport
    ? buildExecutiveReport(opportunity.business, opportunity.report, opportunity.entitlement)
    : null;

  const demonstration = {
    headline: executiveReport?.headline || `Estimated monthly revenue opportunity: $${opportunity.report.totalEstimatedMonthlyLoss.toLocaleString()}`,
    executiveReport,
    proofPoints: opportunity.report.leaks.map(leak => ({
      category: leak.category,
      evidence: leak.evidence,
      confidence: leak.confidence,
      monthlyLoss: leak.estimatedMonthlyLoss,
      recommendedFix: leak.recommendedFix,
    })),
    disclaimer: 'Opportunity estimates are modeled from available evidence and should be validated with merchant-owned data before any claim of recovered revenue.',
  };

  return transition(opportunity, 'demonstrated', { demonstration });
}

export function closeOpportunity(opportunity, close = {}) {
  assertStage(opportunity, 'demonstrated');
  const status = close.status || 'won';
  if (!['won', 'lost', 'deferred'].includes(status)) {
    throw new Error(`Unsupported close status '${status}'.`);
  }
  return transition(opportunity, 'closed', {
    close: {
      status,
      agreedMonthlyFee: money(close.agreedMonthlyFee),
      recoverySharePercent: money(close.recoverySharePercent),
      notes: close.notes || '',
      closedAt: nowIso(),
    },
  });
}

export function beginRecovery(opportunity, { approvedLeakIds = [] } = {}) {
  assertStage(opportunity, 'closed');
  if (opportunity.close?.status !== 'won') {
    throw new Error(`Recovery cannot start for a '${opportunity.close?.status}' opportunity.`);
  }

  const approved = new Set(approvedLeakIds);
  const actions = (opportunity.recoveryPlan || []).map(action => ({
    ...action,
    approved: approved.has(action.leakId),
    status: approved.has(action.leakId) ? 'approved' : 'pending_approval',
  }));

  return transition(opportunity, 'recovering', {
    recovery: {
      startedAt: nowIso(),
      actions,
      approvedActionCount: actions.filter(a => a.approved).length,
    },
  });
}

export function measureOpportunity(opportunity, currentRecord) {
  assertStage(opportunity, 'recovering');
  const currentBusiness = normalizeBusiness(currentRecord);
  const currentReport = diagnose(currentBusiness);

  let comparison;
  if (opportunity.entitlement.capabilities.continuousMonitoring) {
    comparison = compareSnapshots(opportunity.report, currentReport, opportunity.entitlement);
  } else {
    const delta = money(currentReport.totalEstimatedMonthlyLoss - opportunity.report.totalEstimatedMonthlyLoss);
    comparison = {
      previous: opportunity.report.totalEstimatedMonthlyLoss,
      current: currentReport.totalEstimatedMonthlyLoss,
      delta,
      improved: delta < 0,
      recoveredEstimate: delta < 0 ? Math.abs(delta) : 0,
      regressedEstimate: delta > 0 ? delta : 0,
    };
  }

  const measurement = {
    measuredAt: nowIso(),
    currentReport,
    comparison,
    recoveredMonthlyEstimate: money(comparison.recoveredEstimate),
    recoveredAnnualizedEstimate: money(comparison.recoveredEstimate * 12),
    claimStatus: comparison.recoveredEstimate > 0 ? 'modeled-improvement' : 'no-modeled-improvement',
  };

  return transition(opportunity, 'measured', { measurement });
}

export function runDiagnosticWorkflow(record, options = {}) {
  let opportunity = createOpportunity(record, options);
  opportunity = diagnoseOpportunity(opportunity);
  opportunity = quantifyOpportunity(opportunity);
  opportunity = prescribeOpportunity(opportunity);
  opportunity = demonstrateOpportunity(opportunity);
  return opportunity;
}

export function serializeOpportunity(opportunity) {
  const { _engine, _discoveryReport, entitlement, ...safe } = opportunity;
  return {
    ...safe,
    entitlement: entitlement ? { tier: entitlement.tier, enabled: entitlement.enabled, capabilities: entitlement.capabilities } : null,
  };
}
