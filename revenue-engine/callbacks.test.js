import test from 'node:test';
import assert from 'node:assert/strict';
import { fixture, operator, approver, viewer, key } from './test-support.js';
import { RevenueApplication } from './application.js';
import { FileOpportunityStore } from './store.js';
import { vapiBindings } from './vapi.js';

export const callEvent = (overrides = {}) => ({ message: { type: 'end-of-call-report', call: { id: 'vapi-call-1', type: 'inboundPhoneCall', assistantId: 'assistant-a', phoneNumberId: 'number-a', endedAt: new Date(Date.now() - 60000).toISOString(), customer: { number: '+12025550123', name: 'Test Caller' }, ...overrides }, analysis: { summary: 'Please call me about scheduling.', structuredData: { callbackRequested: true } }, artifact: { transcript: 'PRIVATE TRANSCRIPT SHOULD NOT PERSIST' } } });
export async function callbackFixture(t) {
  const data = await fixture(t); const [o] = await data.app.create(operator, { records: [{ id: 'merchant', name: 'Merchant' }] }, key());
  const bindings = [{ tenantId: operator.tenantId, opportunityId: o.id, assistantId: 'assistant-a', phoneNumberId: 'number-a' }];
  data.app.callbacks.bindings = vapiBindings(bindings);
  return { ...data, opportunity: o, bindings };
}
const approval = task => ({ version: task.version, reason: 'Reviewed request and callback number', expiresAt: new Date(Date.now() + 3600000).toISOString() });

test('Vapi intake creates a review task, deduplicates across credentials and omits transcripts', async t => {
  const { app, store } = await callbackFixture(t);
  const event = callEvent();
  const result = await app.callbacks.ingest({ ...operator, role: 'intake' }, event);
  const duplicate = await app.callbacks.ingest({ ...operator, actorId: 'second-importer' }, event);
  assert.equal(duplicate.duplicate, true); assert.equal(duplicate.callbackId, result.callbackId);
  const task = await app.callbacks.read(viewer, result.callbackId);
  assert.equal(task.status, 'pending_approval'); assert.equal(task.call.callbackRequested, true);
  assert.equal(task.contactMode, 'manual'); assert.equal(task.approval, null);
  assert.equal(JSON.stringify(await store.read()).includes('PRIVATE TRANSCRIPT'), false);
  assert.equal((await app.callbacks.list(viewer)).total, 1);
});

test('manual callback requires reviewer approval, records outcome and survives restart', async t => {
  const { app, store, directory, bindings } = await callbackFixture(t);
  const { callbackId } = await app.callbacks.ingest(operator, callEvent());
  let task = await app.callbacks.read(viewer, callbackId);
  await assert.rejects(app.callbacks.change(operator, callbackId, 'outcome', { version: 1, outcome: 'booked', notes: 'Cannot bypass approval' }, key()), { code: 'APPROVAL_REQUIRED' });
  await assert.rejects(app.callbacks.change(operator, callbackId, 'approve', approval(task), key()), { code: 'FORBIDDEN' });
  task = await app.callbacks.change(approver, callbackId, 'approve', approval(task), key());
  const body = { version: task.version, outcome: 'booked', notes: 'Operator reports customer booked a visit' }, requestKey = key();
  task = await app.callbacks.change(operator, callbackId, 'outcome', body, requestKey);
  assert.deepEqual(await app.callbacks.change(operator, callbackId, 'outcome', body, requestKey), task);
  assert.equal(task.attempts.length, 1); assert.equal(task.status, 'completed');
  const summary = await app.callbacks.summary(viewer); assert.equal(summary.reportedBookings, 1); assert.equal(summary.verifiedRecoveredRevenue, null);
  await store.close(); const reopened = await new FileOpportunityStore(directory).open();
  try { assert.deepEqual(await new RevenueApplication({ store: reopened, vapiBindings: bindings }).callbacks.read(viewer, callbackId), task); }
  finally { await reopened.close(); }
});

test('unsupported events and outbound calls are ignored, unbound inputs cannot select a tenant', async t => {
  const { app } = await callbackFixture(t);
  assert.equal((await app.callbacks.ingest(operator, { message: { type: 'status-update' } })).ignored, true);
  assert.equal((await app.callbacks.ingest(operator, callEvent({ type: 'outboundPhoneCall' }))).ignored, true);
  await assert.rejects(app.callbacks.ingest(operator, callEvent({ assistantId: 'other' })), { code: 'VAPI_UNBOUND' });
  await assert.rejects(app.callbacks.ingest({ ...operator, tenantId: 'other' }, callEvent()), { code: 'VAPI_UNBOUND' });
  await assert.rejects(app.callbacks.ingest(viewer, callEvent()), { code: 'FORBIDDEN' });
  assert.equal((await app.callbacks.list(viewer)).total, 0);
});

test('missing number remains reviewable but cannot be approved and cross-tenant reads fail', async t => {
  const { app } = await callbackFixture(t);
  const { callbackId } = await app.callbacks.ingest(operator, callEvent({ customer: {} }));
  const task = await app.callbacks.read(viewer, callbackId);
  assert.equal(task.call.phone, null);
  await assert.rejects(app.callbacks.change(approver, callbackId, 'approve', approval(task), key()), { code: 'CALLBACK_NO_NUMBER' });
  await assert.rejects(app.callbacks.read({ ...viewer, tenantId: 'other' }, callbackId), { code: 'NOT_FOUND' });
  assert.equal((await app.callbacks.summary(viewer)).needsNumber, 1);
});

test('deferring clears approval and a no-answer outcome requires a fresh reviewed attempt', async t => {
  const { app } = await callbackFixture(t); const { callbackId } = await app.callbacks.ingest(operator, callEvent());
  let task = await app.callbacks.read(viewer, callbackId);
  task = await app.callbacks.change(approver, callbackId, 'approve', approval(task), key());
  task = await app.callbacks.change(operator, callbackId, 'outcome', { version: task.version, outcome: 'no_answer', notes: 'No answer on manually placed call', dueAt: new Date(Date.now() + 60000).toISOString() }, key());
  assert.equal(task.status, 'pending_approval'); assert.equal(task.approval, null);
  task = await app.callbacks.change(approver, callbackId, 'approve', approval(task), key());
  await assert.rejects(app.callbacks.change(operator, callbackId, 'outcome', { version: task.version, outcome: 'reached', notes: 'Too early' }, key()), { code: 'CALLBACK_NOT_DUE' });
  task = await app.callbacks.change(operator, callbackId, 'defer', { version: task.version, reason: 'Try later', dueAt: new Date(Date.now() + 120000).toISOString() }, key());
  assert.equal(task.approval, null);
});

test('do-not-call suppresses other pending tasks and prevents later approvals', async t => {
  const { app } = await callbackFixture(t);
  const first = await app.callbacks.ingest(operator, callEvent()); const second = await app.callbacks.ingest(operator, callEvent({ id: 'call-2' }));
  let task = await app.callbacks.read(viewer, first.callbackId);
  task = await app.callbacks.change(approver, task.id, 'approve', approval(task), key());
  await app.callbacks.change(operator, task.id, 'outcome', { version: task.version, outcome: 'do_not_call', notes: 'Caller requested no further calls' }, key());
  assert.equal((await app.callbacks.read(viewer, second.callbackId)).status, 'cancelled');
  const third = await app.callbacks.ingest(operator, callEvent({ id: 'call-3' }));
  task = await app.callbacks.read(viewer, third.callbackId);
  await assert.rejects(app.callbacks.change(approver, task.id, 'approve', approval(task), key()), { code: 'CALLBACK_SUPPRESSED' });
});

test('simultaneous callback edits honor versions and duplicate calls remain one record', async t => {
  const { app } = await callbackFixture(t); const results = await Promise.all([app.callbacks.ingest(operator, callEvent()), app.callbacks.ingest(operator, callEvent())]);
  assert.equal(results.filter(r => r.duplicate).length, 1);
  const task = await app.callbacks.read(viewer, results[0].callbackId);
  const edits = await Promise.allSettled([app.callbacks.change(approver, task.id, 'approve', approval(task), key()), app.callbacks.change(operator, task.id, 'cancel', { version: task.version, reason: 'Not needed' }, key())]);
  assert.equal(edits.filter(e => e.status === 'fulfilled').length, 1);
  assert.equal(edits.find(e => e.status === 'rejected').reason.code, 'VERSION_CONFLICT');
});
