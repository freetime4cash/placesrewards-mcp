import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { FileOpportunityStore } from './store.js';
import { RevenueApplication } from './application.js';

export const operator = { tenantId: 'tenant-a', actorId: 'operator-a', role: 'operator', tier: 'enterprise' };
export const approver = { ...operator, actorId: 'approver-a', role: 'approver' };
export const viewer = { ...operator, actorId: 'viewer-a', role: 'viewer' };
export const baseline = {
  id: 'business-a', name: 'Sandbox Dental', averageTicket: 250, observedAt: '2025-01-01T00:00:00.000Z',
  calls: { missedCallsMonthly: 20, callConversionRate: 0.4, evidence: 'authorized-call-export:1', observedAt: '2025-01-01T00:00:00.000Z' },
  crm: { uncontactedLeadsMonthly: 10, leadCloseRate: 0.2, evidence: 'authorized-crm-export:1', observedAt: '2025-01-01T00:00:00.000Z' },
};
export const key = () => randomUUID();
export const approvalBody = version => ({ version, decision: 'approve', reason: 'Reviewed sandbox action and target', expiresAt: new Date(Date.now() + 3600000).toISOString() });
export async function fixture(t, options = {}) {
  const directory = await mkdtemp(path.join(tmpdir(), 'revenue-engine-test-'));
  const store = await new FileOpportunityStore(directory, options.storeOptions).open();
  const app = new RevenueApplication({ store, ...options });
  t.after(async () => { await store.close(); await rm(directory, { recursive: true, force: true }); });
  return { directory, store, app };
}
export async function recovering(app, actor = operator) {
  let [o] = await app.create(actor, { records: [baseline] }, key());
  for (const operation of ['diagnose','quantify','prescribe','demo','close','recover']) {
    o = await app.advance(actor, o.id, operation, { version: o.version, ...(operation === 'close' ? { status: 'won' } : {}) }, key());
  }
  return o;
}
