import test from 'node:test';
import assert from 'node:assert/strict';
import { fixture, recovering, operator, approver, viewer, baseline, key, approvalBody } from './test-support.js';
import { ConnectorRegistry } from './connectors.js';
import { RevenueApplication } from './application.js';
import { FileOpportunityStore } from './store.js';

const rejects = (promise, code) => assert.rejects(promise, error => error.code === code);

test('full persisted lifecycle keeps modeled revenue separate from sandbox execution', async t => {
  const { app, store, directory } = await fixture(t);
  let o = await recovering(app);
  assert.equal(o.business.signals.length, 4);
  assert.ok(o.report.totalEstimatedMonthlyLoss > 0);
  assert.ok(o.recovery.actions.every(a => a.status === 'pending_approval'));
  const target = o.recovery.actions[0].leakId;
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  o = await app.execute(operator, o.id, 'recovery', target, { version: o.version }, key());
  assert.equal(o.recovery.actions[0].status, 'simulated');
  assert.equal(o.recovery.actions[0].execution.receipt.externalMutation, false);
  const record = structuredClone(baseline); record.calls.missedCallsMonthly = 2;
  o = await app.advance(operator, o.id, 'measure', { version: o.version, record }, key());
  assert.equal(o.stage, 'measured');
  assert.equal(o.measurement.claimStatus, 'modeled-improvement');
  assert.equal(o.measurement.verifiedRecoveredRevenue, null);
  assert.equal((await app.dashboard(viewer)).verifiedRecoveredRevenue, null);
  assert.ok((await app.read(viewer, o.id, 'report')).evidence.signals.every(s => s.evidence));
  await store.close();
  const reopened = await new FileOpportunityStore(directory).open();
  try { assert.deepEqual(await new RevenueApplication({ store: reopened }).read(viewer, o.id), o); }
  finally { await reopened.close(); }
});

test('tenant, role and capability boundaries apply to reads and writes', async t => {
  const { app } = await fixture(t);
  const o = await recovering(app);
  await rejects(app.read({ ...viewer, tenantId: 'tenant-b' }, o.id), 'NOT_FOUND');
  assert.equal((await app.list({ ...viewer, tenantId: 'tenant-b' })).total, 0);
  await rejects(app.create(viewer, { records: [baseline] }, key()), 'FORBIDDEN');
  await rejects(app.approval(operator, o.id, 'recovery', o.recovery.actions[0].leakId, approvalBody(o.version), key()), 'FORBIDDEN');
  await rejects(app.list({ ...viewer, tier: 'disabled' }), 'ENTITLEMENT');
  await rejects(app.create({ ...operator, tier: 'growth' }, { records: [{ ...baseline, id: 'other' }] }, key(), { discover: true }), 'ENTITLEMENT');
  await rejects(app.dashboard({ ...viewer, tier: 'growth' }), 'ENTITLEMENT');
  assert.equal((await app.read({ ...viewer, tier: 'growth' }, o.id)).demonstration.executiveReport, null);
  assert.equal((await app.list({ ...viewer, tier: 'growth' })).items[0].demonstration.executiveReport, null);
  assert.ok((await app.read(viewer, o.id)).demonstration.executiveReport);
  await rejects(app.execute({ ...operator, tier: 'pro' }, o.id, 'recovery', o.recovery.actions[0].leakId, { version: o.version }, key()), 'ENTITLEMENT');
});

test('create is atomic and idempotent, rejects duplicate businesses and changed key payloads', async t => {
  const { app } = await fixture(t);
  const request = { records: [baseline] }, requestKey = key();
  const first = await app.create(operator, request, requestKey);
  assert.deepEqual(await app.create(operator, request, requestKey), first);
  await rejects(app.create(operator, { records: [{ ...baseline, name: 'changed' }] }, requestKey), 'IDEMPOTENCY_CONFLICT');
  await rejects(app.create(operator, request, key()), 'DUPLICATE_BUSINESS');
  await rejects(app.create(operator, { records: [{ ...baseline, id: 'new' }, baseline] }, key(), { discover: true }), 'DUPLICATE_BUSINESS');
  assert.equal((await app.list(viewer)).total, 1);
});

test('optimistic versions prevent simultaneous prospect claims and stale transitions', async t => {
  const { app } = await fixture(t);
  const [o] = await app.create(operator, { records: [baseline] }, key());
  const outcomes = await Promise.allSettled([
    app.queue(operator, o.id, { version: 1, operation: 'claim' }, key()),
    app.queue({ ...operator, actorId: 'other' }, o.id, { version: 1, operation: 'claim' }, key()),
  ]);
  assert.equal(outcomes.filter(x => x.status === 'fulfilled').length, 1);
  assert.equal(outcomes.find(x => x.status === 'rejected').reason.code, 'VERSION_CONFLICT');
  await rejects(app.queue({ ...operator, actorId: 'other' }, o.id, { version: 2, operation: 'claim' }, key()), 'QUEUE_CONFLICT');
  await rejects(app.advance(operator, o.id, 'quantify', { version: 2 }, key()), 'STAGE_CONFLICT');
  let current = await app.queue(operator, o.id, { version: 2, operation: 'snooze', followUpAt: new Date(Date.now() + 60000).toISOString() }, key());
  await rejects(app.queue(operator, o.id, { version: current.version, operation: 'claim' }, key()), 'QUEUE_CONFLICT');
  current = await app.queue(operator, o.id, { version: current.version, operation: 'ready' }, key());
  assert.equal(current.queue.owner, null);
});

test('recovery requires a matching unexpired approval; replay and concurrency execute once', async t => {
  let count = 0;
  const connectors = new ConnectorRegistry({ execution: { id: 'counting', mode: 'sandbox', async execute({ executionId }) { count++; return { executionId, mode: 'sandbox', status: 'simulated', externalMutation: false, reference: 'test' }; } } });
  const { app } = await fixture(t, { connectors });
  let o = await recovering(app); const target = o.recovery.actions[0].leakId;
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  await rejects(app.approval(approver, o.id, 'recovery', target, { ...approvalBody(o.version), expiresAt: '2020-01-01T00:00:00Z' }, key()), 'VALIDATION');
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  const request = { version: o.version }, requestKey = key();
  await Promise.all([app.execute(operator, o.id, 'recovery', target, request, requestKey), app.execute(operator, o.id, 'recovery', target, request, requestKey)]);
  o = await app.execute(operator, o.id, 'recovery', target, request, requestKey);
  assert.equal(count, 1); assert.equal(o.recovery.actions[0].status, 'simulated');
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  assert.equal(count, 1);
});

test('approval revocation, payload changes and expired approval prevent dispatch', async t => {
  const { app, store } = await fixture(t);
  let o = await recovering(app); const target = o.recovery.actions[0].leakId;
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  o = await app.approval(approver, o.id, 'recovery', target, { version: o.version, decision: 'revoke', reason: 'Review withdrawn' }, key());
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  await store.transaction(state => { state.opportunities[o.id].recovery.actions[0].action = 'modified payload'; });
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_INVALID');
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  await store.transaction(state => { state.opportunities[o.id].recovery.actions[0].approval.expiresAt = '2020-01-01T00:00:00Z'; });
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_INVALID');
});

test('provider errors and timeouts stay uncertain until reviewed; retry requires a new approval', async t => {
  for (const mode of ['throw','timeout','invalid']) await t.test(mode, async t => {
    const connectors = new ConnectorRegistry({ execution: { id: 'failing', mode: 'sandbox', async execute() { if (mode === 'timeout') return new Promise(() => {}); if (mode === 'invalid') return { status: 'done' }; throw new Error('provider-secret'); } } });
    const { app, store } = await fixture(t, { connectors, executionTimeoutMs: 20 });
    let o = await recovering(app); const target = o.recovery.actions[0].leakId;
    o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
    o = await app.execute(operator, o.id, 'recovery', target, { version: o.version }, key());
    assert.equal(o.recovery.actions[0].status, 'uncertain');
    assert.equal(JSON.stringify(await store.read()).includes('provider-secret'), false);
    await rejects(app.advance(operator, o.id, 'measure', { version: o.version, record: baseline }, key()), 'EXECUTION_UNRESOLVED');
    await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
    o = await app.reconcile(approver, o.id, 'recovery', target, { version: o.version, outcome: 'not_executed', evidence: 'Sandbox provider reviewed' }, key());
    assert.equal(o.recovery.actions[0].status, 'pending_approval');
    assert.equal(o.recovery.actions[0].executions.length, 1);
    await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  });
});

test('restart marks durable interrupted intents uncertain without executing anything', async t => {
  const { app, store, directory } = await fixture(t);
  let o = await recovering(app);
  await store.transaction(state => { const action = state.opportunities[o.id].recovery.actions[0]; action.status = 'executing'; action.execution = { id: 'interrupted' }; });
  await store.close();
  const reopened = await new FileOpportunityStore(directory).open();
  try {
    const restored = new RevenueApplication({ store: reopened });
    await restored.recoverInterruptedExecutions();
    o = await restored.read(viewer, o.id);
    assert.equal(o.recovery.actions[0].status, 'uncertain');
    assert.equal(o.recovery.actions[0].execution.error.code, 'INTERRUPTED');
  } finally { await reopened.close(); }
});

test('measurement rejects missing, stale, different-business and changed-ticket evidence', async t => {
  const { app } = await fixture(t); const o = await recovering(app);
  for (const [record, code] of [
    [{ ...baseline, id: 'wrong' }, 'VALIDATION'],
    [{ id: baseline.id, name: baseline.name, averageTicket: 250 }, 'INCOMPLETE_EVIDENCE'],
    [{ ...baseline, averageTicket: 1 }, 'INCOMPLETE_EVIDENCE'],
    [{ ...baseline, calls: { ...baseline.calls, observedAt: '2024-01-01T00:00:00Z' } }, 'INCOMPLETE_EVIDENCE'],
  ]) await rejects(app.advance(operator, o.id, 'measure', { version: o.version, record }, key()), code);
});

test('outreach draft, approval, simulated send and audit form a separate gated workflow', async t => {
  const { app } = await fixture(t);
  let [o] = await app.create(operator, { records: [baseline] }, key());
  o = await app.draftOutreach(operator, o.id, { version: o.version, channel: 'email', recipient: 'owner@example.test', subject: 'Review opportunity', body: 'Modeled opportunity for review.' }, key());
  const target = o.outreach[0].id;
  await rejects(app.execute(operator, o.id, 'outreach', target, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  o = await app.approval(approver, o.id, 'outreach', target, approvalBody(o.version), key());
  o = await app.execute(operator, o.id, 'outreach', target, { version: o.version }, key());
  assert.equal(o.outreach[0].status, 'simulated');
  const audit = await app.auditLog(approver);
  assert.ok(audit.items.some(entry => entry.operation.endsWith(':approve') && entry.actorId === approver.actorId));
  assert.equal(JSON.stringify(audit).includes('owner@example.test'), false);
  assert.equal((await app.auditLog({ ...approver, tenantId: 'other' })).total, 0);
});

test('lost deals cannot recover; deferred deals explicitly reopen', async t => {
  const { app } = await fixture(t);
  for (const status of ['lost','deferred']) {
    let [o] = await app.create(operator, { records: [{ ...baseline, id: status }] }, key());
    for (const step of ['diagnose','quantify','prescribe','demo']) o = await app.advance(operator, o.id, step, { version: o.version }, key());
    o = await app.advance(operator, o.id, 'close', { version: o.version, status }, key());
    await rejects(app.advance(operator, o.id, 'recover', { version: o.version }, key()), 'STAGE_CONFLICT');
    if (status === 'deferred') assert.equal((await app.advance(operator, o.id, 'reopen', { version: o.version }, key())).stage, 'demonstrated');
    else await rejects(app.advance(operator, o.id, 'reopen', { version: o.version }, key()), 'STAGE_CONFLICT');
  }
});

test('import adapters validate batch evidence, preserve provenance and rank prospects', async t => {
  const { app } = await fixture(t);
  const records = [baseline, { ...baseline, id: 'low', calls: { ...baseline.calls, missedCallsMonthly: 1 }, crm: { ...baseline.crm, uncontactedLeadsMonthly: 1 } }];
  const list = await app.create(operator, { records }, key(), { discover: true, provider: 'call-export' });
  assert.equal(list[0].business.id, baseline.id);
  assert.equal(list[0].source, 'call-export');
  assert.equal((await app.list(viewer, { offset: '0', limit: '1' })).nextOffset, 1);
  await rejects(app.create(operator, { records: [{ id: 'bad', name: 'Missing calls' }] }, key(), { discover: true, provider: 'call-export' }), 'VALIDATION');
});
