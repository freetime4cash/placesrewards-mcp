import test from 'node:test';
import assert from 'node:assert/strict';
import { fixture, recovering, operator, approver, key, approvalBody, baseline } from './test-support.js';
import { ConnectorRegistry } from './connectors.js';
import { FileOpportunityStore } from './store.js';
import { RevenueApplication } from './application.js';

const draft = version => ({ version, event: { id: 'call-1', caller: '+12025550123', businessNumber: '+12025550124', occurredAt: new Date(Date.now() - 60000).toISOString(), evidence: 'sandbox:missed-call-1' }, smsPermission: { allowed: true, evidence: 'sandbox:permission-record' }, bookingUrl: 'https://example.test/book' });
const rejects = (promise, code) => assert.rejects(promise, error => error.code === code);

test('missed call creates an exact-recipient SMS action, approval is required and execution replays once', async t => {
  let payload, count = 0;
  const connectors = new ConnectorRegistry({ execution: { id: 'sms-test', mode: 'sandbox', async execute(request) {
    payload = request.payload; count++;
    return { executionId: request.executionId, mode: 'sandbox', status: 'simulated', externalMutation: false, reference: 'sms-simulation-1' };
  } } });
  const { app } = await fixture(t, { connectors });
  let o = await recovering(app);
  const body = draft(o.version), requestKey = key();
  o = await app.missedCall(operator, o.id, body, requestKey);
  assert.deepEqual(await app.missedCall(operator, o.id, body, requestKey), o);
  const action = o.recovery.actions.at(-1);
  assert.equal(action.status, 'pending_approval');
  assert.equal(action.estimatedMonthlyRecovery, 0);
  assert.match(action.missedCall.message, /Sandbox Dental/);
  assert.match(action.missedCall.message, /https:\/\/example.test\/book/);
  assert.match(action.missedCall.message, /Reply STOP/);
  await rejects(app.execute(operator, o.id, 'recovery', action.leakId, { version: o.version }, key()), 'APPROVAL_REQUIRED');
  o = await app.approval(approver, o.id, 'recovery', action.leakId, approvalBody(o.version), key());
  const executionKey = key(), executionBody = { version: o.version };
  o = await app.execute(operator, o.id, 'recovery', action.leakId, executionBody, executionKey);
  await app.execute(operator, o.id, 'recovery', action.leakId, executionBody, executionKey);
  assert.equal(count, 1); assert.equal(payload.missedCall.to, body.event.caller);
  assert.equal(payload.missedCall.from, body.event.businessNumber);
  assert.equal(payload.missedCall.body, action.missedCall.message);
  assert.equal(o.recovery.actions.at(-1).status, 'simulated');
});

test('invalid, stale, duplicate and non-permitted calls cannot create follow-up actions', async t => {
  const { app } = await fixture(t); let o = await recovering(app);
  for (const change of [b => { b.event.caller = '555-1234'; }, b => { b.event.occurredAt = '2020-01-01T00:00:00Z'; }, b => { b.bookingUrl = 'javascript:alert(1)'; }, b => { b.event.caller = b.event.businessNumber; }]) {
    const body = draft(o.version); change(body);
    await rejects(app.missedCall(operator, o.id, body, key()), 'VALIDATION');
  }
  await rejects(app.missedCall(operator, o.id, { ...draft(o.version), smsPermission: { allowed: false, evidence: 'none' } }, key()), 'SMS_PERMISSION_REQUIRED');
  await rejects(app.missedCall({ ...operator, tier: 'pro' }, o.id, draft(o.version), key()), 'ENTITLEMENT');
  o = await app.missedCall(operator, o.id, draft(o.version), key());
  await rejects(app.missedCall(operator, o.id, draft(o.version), key()), 'DUPLICATE_CALL');
});

test('scheduled and expired follow-ups cannot dispatch; recipient changes invalidate approval', async t => {
  const { app, store } = await fixture(t);
  let o = await recovering(app);
  o = await app.missedCall(operator, o.id, { ...draft(o.version), sendAfter: new Date(Date.now() + 3600000).toISOString() }, key());
  const target = o.recovery.actions.at(-1).leakId;
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'FOLLOWUP_NOT_DUE');
  await store.transaction(state => { state.opportunities[o.id].recovery.actions.at(-1).missedCall.sendAfter = new Date(Date.now() - 1000).toISOString(); });
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_INVALID');
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  await store.transaction(state => { state.opportunities[o.id].recovery.actions.at(-1).missedCall.caller = '+12025550199'; });
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'APPROVAL_INVALID');
  await store.transaction(state => { state.opportunities[o.id].recovery.actions.at(-1).missedCall.expiresAt = '2020-01-01T00:00:00Z'; });
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'FOLLOWUP_EXPIRED');
});

test('STOP survives restart, suppresses later calls and cannot be undone by a reply', async t => {
  const { app, store, directory } = await fixture(t);
  let o = await recovering(app);
  o = await app.missedCall(operator, o.id, draft(o.version), key());
  const target = o.recovery.actions.at(-1).leakId;
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  o = await app.missedCallResponse(operator, o.id, target, { version: o.version, eventId: 'reply-1', text: ' STOP ', outcome: 'replied' }, key());
  assert.equal(o.recovery.actions.at(-1).missedCall.disposition, 'opted_out');
  assert.equal(o.recovery.actions.at(-1).approval, null);
  await rejects(app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key()), 'FOLLOWUP_STOPPED');
  o = await app.missedCallResponse(operator, o.id, target, { version: o.version, eventId: 'reply-2', text: 'Hello', outcome: 'replied' }, key());
  assert.equal(o.recovery.actions.at(-1).missedCall.disposition, 'opted_out');
  await store.close(); const reopened = await new FileOpportunityStore(directory).open();
  try {
    const restored = new RevenueApplication({ store: reopened });
    const body = draft(o.version); body.event.id = 'later-call';
    await rejects(restored.missedCall(operator, o.id, body, key()), 'SMS_SUPPRESSED');
  } finally { await reopened.close(); }
});

test('a reply stops pending follow-up and cross-tenant responses are rejected', async t => {
  const { app } = await fixture(t); let o = await recovering(app);
  o = await app.missedCall(operator, o.id, draft(o.version), key());
  const target = o.recovery.actions.at(-1).leakId;
  const response = { version: o.version, eventId: 'reply-1', text: 'Please call me back', outcome: 'callback_requested' };
  await rejects(app.missedCallResponse({ ...operator, tenantId: 'other' }, o.id, target, response, key()), 'NOT_FOUND');
  o = await app.missedCallResponse(operator, o.id, target, response, key());
  assert.equal(o.recovery.actions.at(-1).missedCall.disposition, 'callback_requested');
  await rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()), 'FOLLOWUP_STOPPED');
  await rejects(app.missedCallResponse(operator, o.id, target, { ...response, version: o.version }, key()), 'DUPLICATE_RESPONSE');
});
