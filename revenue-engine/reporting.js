export const CONTRACT_VERSION = '1.0';
const money = value => Math.round(value * 100) / 100;

export function opportunityReport(opportunity) {
  return {
    contractVersion: CONTRACT_VERSION, opportunityId: opportunity.id, version: opportunity.version,
    business: { id: opportunity.business.id, name: opportunity.business.name }, stage: opportunity.stage,
    evidence: { signals: opportunity.business.signals, quality: opportunity.evidenceQuality },
    diagnosis: opportunity.report, quantification: opportunity.quantified,
    prescription: opportunity.recoveryPlan, demonstration: opportunity.demonstration,
    close: opportunity.close, recovery: opportunity.recovery, measurement: opportunity.measurement,
    currency: 'USD', claimStatus: opportunity.measurement?.claimStatus || 'modeled-opportunity',
    verifiedRecoveredRevenue: null,
    disclaimer: 'Modeled opportunities can overlap and are not additive verified cash. Sandbox executions do not recover revenue. Measurement does not establish causation.',
  };
}

export function dashboard(opportunities) {
  const stages = Object.fromEntries(['discovered','diagnosed','quantified','prescribed','demonstrated','closed','recovering','measured'].map(stage => [stage, 0]));
  const closeOutcomes = { won: 0, lost: 0, deferred: 0 };
  let monthly = 0, weighted = 0, modeledImprovement = 0, pendingApproval = 0, uncertain = 0;
  for (const o of opportunities) {
    stages[o.stage]++;
    if (o.close) closeOutcomes[o.close.status]++;
    if (!['lost','deferred'].includes(o.close?.status)) {
      monthly += o.quantified?.modeledMonthlyLoss || 0;
      weighted += o.quantified?.confidenceWeightedMonthlyOpportunity || 0;
    }
    modeledImprovement += o.measurement?.recoveredMonthlyEstimate || 0;
    for (const action of [...(o.recovery?.actions || []), ...o.outreach]) {
      if (action.status === 'pending_approval' || action.status === 'draft') pendingApproval++;
      if (['executing','uncertain'].includes(action.status)) uncertain++;
    }
  }
  return { contractVersion: CONTRACT_VERSION, currency: 'USD', total: opportunities.length, stages, closeOutcomes,
    modeledMonthlyOpportunity: money(monthly), confidenceWeightedMonthlyOpportunity: money(weighted),
    modeledMonthlyImprovement: money(modeledImprovement), verifiedRecoveredRevenue: null,
    pendingApproval, uncertainExecutions: uncertain, executionMode: 'sandbox',
    disclaimer: 'Modeled estimates only; overlapping losses may overstate totals. No verified recovered cash.' };
}
