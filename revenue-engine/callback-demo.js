import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { FileOpportunityStore } from './store.js';
import { RevenueApplication } from './application.js';

const directory = await mkdtemp(path.join(tmpdir(), 'revenue-callback-demo-'));
const store = await new FileOpportunityStore(directory).open();
try {
  const app = new RevenueApplication({ store });
  const operator = { tenantId: 'demo', actorId: 'operator', role: 'operator', tier: 'growth' };
  const approver = { ...operator, actorId: 'reviewer', role: 'approver' };
  const [business] = await app.create(operator, { records: [{ id: 'demo-business', name: 'Demo Business' }] }, randomUUID());
  app.callbacks.bindings = [{ tenantId: 'demo', opportunityId: business.id, assistantId: 'demo-assistant', phoneNumberId: 'demo-number' }];
  const captured = await app.callbacks.ingest(operator, { message: { type: 'end-of-call-report', call: { id: 'demo-call', type: 'inboundPhoneCall', assistantId: 'demo-assistant', phoneNumberId: 'demo-number', endedAt: new Date().toISOString(), customer: { number: '+12025550123' } }, analysis: { summary: 'Synthetic caller requested help scheduling a visit.', structuredData: { callbackRequested: true } } } });
  let task = await app.callbacks.read(operator, captured.callbackId);
  task = await app.callbacks.change(approver, task.id, 'approve', { version: task.version, reason: 'Synthetic callback review', expiresAt: new Date(Date.now() + 60000).toISOString() }, randomUUID());
  await app.callbacks.change(operator, task.id, 'outcome', { version: task.version, outcome: 'booked', notes: 'Synthetic manually recorded outcome; no call was placed.' }, randomUUID());
  console.log(JSON.stringify({ ...(await app.callbacks.summary(operator)), demoOnly: true, actualCallsPlaced: 0, smsSent: 0 }, null, 2));
} finally { await store.close(); await rm(directory, { recursive: true, force: true }); }
