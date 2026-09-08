import test from 'node:test';
import assert from 'node:assert/strict';
import { importVapi } from './vapi-import.js';
import { localClient } from './local-client.js';
import { runCli } from './cli.js';

const env = { REVENUE_VAPI_API_KEY: 'private-test-key-12345', REVENUE_VAPI_BINDINGS: JSON.stringify([{ tenantId: 'a', opportunityId: 'o', assistantId: 'assistant-a', phoneNumberId: 'number-a' }]) };
const call = { id: 'one', type: 'inboundPhoneCall', status: 'ended', assistantId: 'assistant-a', phoneNumberId: 'number-a', endedAt: new Date(Date.now() - 60000).toISOString(), customer: { number: '+12025550123' }, artifact: { transcript: 'secret transcript' } };
test('Vapi pull uses GET only, strips artifacts and posts only eligible calls locally', async () => {
  const calls = [], events = [];
  const result = await importVapi({ env, transport: async (url, options) => { calls.push({ url, options }); return new Response(JSON.stringify([call, { ...call, id: 'two', status: 'in-progress' }, { ...call, id: 'three', assistantId: 'other' }])); }, client: async (url, event) => { events.push({ url, event }); return { duplicate: false }; } });
  assert.equal(calls.length, 1); assert.equal(calls[0].url, 'https://api.vapi.ai/call?limit=100'); assert.equal(calls[0].options.method, 'GET'); assert.equal(calls[0].options.redirect, 'error');
  assert.equal(result.imported, 1); assert.equal(result.ignored, 2);
  assert.equal(JSON.stringify(events).includes('secret transcript'), false);
  assert.equal(JSON.stringify(events).includes(env.REVENUE_VAPI_API_KEY), false);
});
test('full batches, provider failures and malformed formats fail without partial intake', async () => {
  for (const [response, code] of [[new Response(JSON.stringify(Array(100).fill(call))), 'VAPI_BATCH_FULL'], [new Response('secret provider response', { status: 401 }), 'VAPI_READ_FAILED'], [new Response('{}'), 'VAPI_FORMAT'], [new Response('not-json'), 'INVALID_RESPONSE']]) {
    let imported = false;
    await assert.rejects(importVapi({ env, transport: async () => response, client: async () => { imported = true; } }), { code });
    assert.equal(imported, false);
  }
});
test('local client pins loopback and never follows redirects or includes Vapi credentials', async () => {
  let request;
  const client = localClient({ REVENUE_PORT: '4311', REVENUE_CLIENT_TOKEN: 'x'.repeat(40), REVENUE_VAPI_API_KEY: 'private-vapi' }, async (url, options) => { request = { url, options }; return new Response(JSON.stringify({ ok: true, data: { total: 1 } })); });
  assert.equal((await client('/v1/callbacks')).total, 1);
  assert.equal(request.url, 'http://127.0.0.1:4311/v1/callbacks'); assert.equal(request.options.redirect, 'error');
  assert.equal(JSON.stringify(request).includes('private-vapi'), false);
  await assert.rejects(client('https://other.example'), { code: 'VALIDATION' });
});
test('CLI has an offline help path and cannot create outbound calls', async () => {
  const help = await runCli(['help'], null); assert.ok(help.commands.includes('callbacks'));
  await assert.rejects(runCli(['dial', '+12025550123'], () => { throw new Error('should not execute'); }), { code: 'USAGE' });
  let route; await runCli(['callbacks'], async value => { route = value; }); assert.equal(route, '/v1/callbacks');
});
