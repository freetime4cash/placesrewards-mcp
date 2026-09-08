import test from 'node:test';
import assert from 'node:assert/strict';
import {
  runDiagnosticWorkflow,
  closeOpportunity,
  beginRecovery,
  measureOpportunity,
  serializeOpportunity,
} from './workflow.js';

const baseline = {
  id: 'biz-100',
  name: 'Example Local Business',
  industry: 'home-services',
  averageTicket: 250,
  metrics: {
    missed_calls_monthly: 30,
    call_conversion_rate: 0.4,
    uncontacted_leads_monthly: 10,
    lead_close_rate: 0.25,
    dormant_customers: 100,
    reactivation_rate: 0.1,
    repeat_purchase_gap_monthly: 8,
    review_rating: 3.9,
    review_count: 50,
  },
};

test('runs discover through demonstrate as an isolated diagnostic workflow', () => {
  const opportunity = runDiagnosticWorkflow(baseline, { tier: 'pro', source: 'test' });

  assert.equal(opportunity.stage, 'demonstrated');
  assert.ok(opportunity.report.leaks.length >= 4);
  assert.ok(opportunity.quantified.modeledAnnualLoss > 0);
  assert.equal(opportunity.recoveryPlan.every(x => x.approvalRequired), true);
  assert.ok(opportunity.demonstration.executiveReport);
  assert.deepEqual(
    opportunity.history.map(x => x.stage),
    ['discovered', 'diagnosed', 'quantified', 'prescribed', 'demonstrated']
  );
});

test('won opportunity can enter recovery only with explicit action approvals', () => {
  let opportunity = runDiagnosticWorkflow(baseline, { tier: 'enterprise' });
  opportunity = closeOpportunity(opportunity, {
    status: 'won',
    agreedMonthlyFee: 499,
    recoverySharePercent: 10,
  });

  const approvedLeakId = opportunity.recoveryPlan[0].leakId;
  opportunity = beginRecovery(opportunity, { approvedLeakIds: [approvedLeakId] });

  assert.equal(opportunity.stage, 'recovering');
  assert.equal(opportunity.recovery.approvedActionCount, 1);
  assert.equal(opportunity.recovery.actions[0].status, 'approved');
  assert.equal(opportunity.recovery.actions.slice(1).every(x => x.status === 'pending_approval'), true);
});

test('lost opportunity cannot start recovery', () => {
  let opportunity = runDiagnosticWorkflow(baseline, { tier: 'pro' });
  opportunity = closeOpportunity(opportunity, { status: 'lost' });
  assert.throws(() => beginRecovery(opportunity), /Recovery cannot start/);
});

test('measurement reports modeled recovered revenue after leak reduction', () => {
  let opportunity = runDiagnosticWorkflow(baseline, { tier: 'enterprise' });
  opportunity = closeOpportunity(opportunity, { status: 'won' });
  opportunity = beginRecovery(opportunity, {
    approvedLeakIds: opportunity.recoveryPlan.map(x => x.leakId),
  });

  const improved = {
    ...baseline,
    metrics: {
      ...baseline.metrics,
      missed_calls_monthly: 5,
      uncontacted_leads_monthly: 2,
      dormant_customers: 40,
      repeat_purchase_gap_monthly: 3,
      review_rating: 4.4,
    },
  };

  opportunity = measureOpportunity(opportunity, improved);

  assert.equal(opportunity.stage, 'measured');
  assert.equal(opportunity.measurement.comparison.improved, true);
  assert.ok(opportunity.measurement.recoveredMonthlyEstimate > 0);
  assert.equal(opportunity.measurement.claimStatus, 'modeled-improvement');
});

test('serialized opportunity excludes internal runtime fields', () => {
  const opportunity = runDiagnosticWorkflow(baseline, { tier: 'pro' });
  const serialized = serializeOpportunity(opportunity);

  assert.equal('_engine' in serialized, false);
  assert.equal('_discoveryReport' in serialized, false);
  assert.equal(serialized.entitlement.tier, 'pro');
});
