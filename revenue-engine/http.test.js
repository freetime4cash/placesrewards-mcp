import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { fixture, baseline, key } from './test-support.js';
import { loadConfig } from './config.js';
import { createRevenueHttpServer } from './http.js';

const credentials = [
  { token: 'o'.repeat(40), tenantId: 'tenant-a', actorId: 'operator', role: 'operator', tier: 'enterprise' },
  { token: 'a'.repeat(40), tenantId: 'tenant-a', actorId: 'approver', role: 'approver', tier: 'enterprise' },
  { token: 'v'.repeat(40), tenantId: 'tenant-a', actorId: 'viewer', role: 'viewer', tier: 'enterprise' },
  { token: 'b'.repeat(40), tenantId: 'tenant-b', actorId: 'other', role: 'operator', tier: 'enterprise' },
];
export const configEnv = { REVENUE_ENABLED: 'true', REVENUE_ENV: 'test', REVENUE_AUTH: JSON.stringify(credentials) };
async function httpFixture(t, options = {}) {
  const { app } = await fixture(t);
  const logs = [];
  const server = createRevenueHttpServer({ app, principals: loadConfig(configEnv).principals, logger: entry => logs.push(entry), ...options });
  server.listen(0, '127.0.0.1'); await once(server, 'listening');
  t.after(async () => { await new Promise(resolve => server.close(resolve)); await server.drain(); });
  const base = `http://127.0.0.1:${server.address().port}`;
  async function request(route, body, { token = credentials[0].token, requestKey = key(), headers = {}, method = body === undefined ? 'GET' : 'POST' } = {}) {
    const response = await fetch(base + route, { method, headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', 'Idempotency-Key': requestKey, ...headers }, ...(body === undefined ? {} : { body: typeof body === 'string' ? body : JSON.stringify(body) }) });
    return { status: response.status, headers: response.headers, body: await response.json() };
  }
  return { app, logs, request };
}

test('HTTP lifecycle, reports and explicit recovery approvals work end to end', async t => {
  const { request } = await httpFixture(t);
  const response = await request('/v1/discover', { records: [baseline] });
  assert.equal(response.status, 200); let o = response.body.data[0];
  for (const step of ['diagnose','quantify','prescribe','demo','close','recover']) {
    const response = await request(`/v1/opportunities/${o.id}/${step}`, { version: o.version, ...(step === 'close' ? { status: 'won' } : {}) });
    assert.equal(response.status, 200, JSON.stringify(response.body)); o = response.body.data;
  }
  const actionPath = `/v1/opportunities/${o.id}/recovery/${encodeURIComponent(o.recovery.actions[0].leakId)}`;
  assert.equal((await request(actionPath + '/execute', { version: o.version })).status, 409);
  o = (await request(actionPath + '/approval', { version: o.version, decision: 'approve', reason: 'Sandbox review', expiresAt: new Date(Date.now() + 60000).toISOString() }, { token: credentials[1].token })).body.data;
  o = (await request(actionPath + '/execute', { version: o.version })).body.data;
  assert.equal(o.recovery.actions[0].status, 'simulated');
  const record = structuredClone(baseline); record.calls.missedCallsMonthly = 1;
  const measurement = await request(`/v1/opportunities/${o.id}/measure`, { version: o.version, record });
  assert.equal(measurement.status, 200); assert.equal(measurement.body.data.stage, 'measured');
  for (const suffix of ['', '/evidence', '/report']) assert.equal((await request(`/v1/opportunities/${o.id}${suffix}`)).status, 200);
  const dashboard = await request('/v1/dashboard');
  assert.equal(dashboard.body.data.stages.measured, 1);
  assert.equal(dashboard.body.data.verifiedRecoveredRevenue, null);
  assert.equal(dashboard.headers.get('cache-control'), 'no-store');
});

test('HTTP auth rejects cross-tenant access, body privileges and viewer writes', async t => {
  const { request } = await httpFixture(t);
  assert.equal((await request('/health', undefined, { token: '' })).status, 200);
  assert.equal((await request('/v1/opportunities', undefined, { token: '' })).status, 401);
  const [o] = (await request('/v1/opportunities', { records: [baseline] })).body.data;
  assert.equal((await request(`/v1/opportunities/${o.id}`, undefined, { token: credentials[3].token })).status, 404);
  assert.equal((await request('/v1/opportunities', { records: [baseline] }, { token: credentials[2].token })).status, 403);
  assert.equal((await request('/v1/opportunities', { records: [{ ...baseline, tenantId: 'other', tier: 'enterprise' }] })).status, 400);
  assert.equal((await request('/v1/audit')).status, 403);
});

test('HTTP validates JSON, limits, paths, pagination and idempotency keys', async t => {
  const { request } = await httpFixture(t, { maxBodyBytes: 2048 });
  const cases = [
    ['/v1/opportunities', '{broken', {}, 400],
    ['/v1/opportunities', { records: [baseline] }, { headers: { 'Content-Type': 'text/plain' } }, 415],
    ['/v1/opportunities', { records: [baseline] }, { requestKey: '' }, 400],
    ['/v1/opportunities', 'x'.repeat(2049), {}, 413],
    ['/v1/opportunities?limit=-1', undefined, {}, 400],
    ['/v1/opportunities?limit=1&limit=2', undefined, {}, 400],
    ['/v1/opportunities?unknown=1', undefined, {}, 400],
    ['/v1/opportunities/%ZZ', undefined, {}, 400],
    ['/v1/missing', undefined, {}, 404],
    ['/v1/opportunities', undefined, { method: 'DELETE' }, 405],
  ];
  for (const [route, body, options, expected] of cases) assert.equal((await request(route, body, options)).status, expected, route);
  const requestKey = key();
  const first = await request('/v1/opportunities', { records: [baseline] }, { requestKey });
  assert.deepEqual((await request('/v1/opportunities', { records: [baseline] }, { requestKey })).body.data, first.body.data);
  assert.equal((await request('/v1/opportunities', { records: [{ ...baseline, name: 'Changed' }] }, { requestKey })).status, 409);
});

test('request logging omits credentials, body data and internal failures', async t => {
  const { request, logs, app } = await httpFixture(t);
  await request('/v1/opportunities', { records: [{ ...baseline, name: 'private-merchant-name' }] });
  app.list = async () => { throw new Error('private-provider-secret'); };
  const failure = await request('/v1/opportunities');
  assert.equal(failure.status, 500);
  assert.equal(failure.body.error.code, 'INTERNAL');
  const text = JSON.stringify({ logs, failure });
  for (const secret of ['private-merchant-name', 'private-provider-secret', credentials[0].token]) assert.equal(text.includes(secret), false);
  assert.ok(logs.every(entry => entry.requestId && Number.isInteger(entry.durationMs)));
});

test('HTTP import, prospect queue and outreach endpoints persist their workflow', async t => {
  const { request } = await httpFixture(t);
  assert.equal((await request('/v1/providers')).body.data.execution.mode, 'sandbox');
  let [o] = (await request('/v1/providers/crm-export/import', { records: [baseline] })).body.data;
  o = (await request(`/v1/opportunities/${o.id}/queue`, { version: o.version, operation: 'claim' })).body.data;
  assert.equal((await request('/v1/prospects?queue=claimed')).body.data.total, 1);
  o = (await request(`/v1/opportunities/${o.id}/outreach`, { version: o.version, channel: 'email', recipient: 'review@example.test', subject: 'Sandbox', body: 'Modeled estimate only.' })).body.data;
  const route = `/v1/opportunities/${o.id}/outreach/${o.outreach[0].id}`;
  o = (await request(route + '/approval', { version: o.version, decision: 'approve', reason: 'Reviewed draft', expiresAt: new Date(Date.now() + 60000).toISOString() }, { token: credentials[1].token })).body.data;
  o = (await request(route + '/execute', { version: o.version })).body.data;
  assert.equal(o.outreach[0].execution.receipt.externalMutation, false);
  const outcome = await request(route + '/outcome', { version: o.version, outcome: 'meeting_booked', notes: 'Sandbox meeting simulation', followUpAt: new Date(Date.now() + 60000).toISOString() });
  assert.equal(outcome.status, 200);
  assert.equal(outcome.body.data.queue.status, 'snoozed');
  assert.ok((await request('/v1/audit', undefined, { token: credentials[1].token })).body.data.total > 0);
});

test('HTTP missed-call draft, approval, simulation and reply route form a complete flow', async t => {
  const { request } = await httpFixture(t);
  let [o] = (await request('/v1/opportunities', { records: [baseline] })).body.data;
  for (const step of ['diagnose','quantify','prescribe','demo','close','recover']) o = (await request(`/v1/opportunities/${o.id}/${step}`, { version: o.version, ...(step === 'close' ? { status: 'won' } : {}) })).body.data;
  const call = await request(`/v1/opportunities/${o.id}/missed-calls`, { version: o.version,
    event: { id: 'http-call-1', caller: '+12025550123', businessNumber: '+12025550124', occurredAt: new Date(Date.now() - 60000).toISOString(), evidence: 'sandbox:call-1' },
    smsPermission: { allowed: true, evidence: 'sandbox:permission' } });
  assert.equal(call.status, 200); o = call.body.data;
  const target = o.recovery.actions.at(-1).leakId;
  const route = `/v1/opportunities/${o.id}/recovery/${encodeURIComponent(target)}`;
  o = (await request(route + '/approval', { version: o.version, decision: 'approve', reason: 'Reviewed missed call SMS', expiresAt: new Date(Date.now() + 60000).toISOString() }, { token: credentials[1].token })).body.data;
  o = (await request(route + '/execute', { version: o.version })).body.data;
  assert.equal(o.recovery.actions.at(-1).status, 'simulated');
  const reply = await request(`/v1/opportunities/${o.id}/missed-calls/${encodeURIComponent(target)}/response`, { version: o.version, eventId: 'http-reply-1', text: 'Booked a time', outcome: 'booked' });
  assert.equal(reply.status, 200);
  const summary = (await request('/v1/dashboard')).body.data.missedCalls;
  assert.equal(summary.booked, 1); assert.equal(summary.simulated, 1);
  assert.equal(summary.pendingApproval, 0);
});
