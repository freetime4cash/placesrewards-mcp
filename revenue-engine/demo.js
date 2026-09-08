import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { FileOpportunityStore } from './store.js';
import { RevenueApplication } from './application.js';

// Disposable, synthetic evidence only. This program never reads production settings or data.
const directory = await mkdtemp(path.join(tmpdir(), 'revenue-demo-'));
const store = await new FileOpportunityStore(directory).open();
try {
  const app = new RevenueApplication({ store });
  const operator = { actorId: 'demo-operator', tenantId: 'demo', role: 'operator', tier: 'enterprise' };
  const approver = { ...operator, actorId: 'demo-reviewer', role: 'approver' };
  const record = { id: 'demo-dental', name: 'Synthetic Dental', averageTicket: 250, calls: { missedCallsMonthly: 20, callConversionRate: 0.4, evidence: 'synthetic-call-log' } };
  let [opportunity] = await app.create(operator, { records: [record] }, randomUUID());
  for (const operation of ['diagnose','quantify','prescribe','demo','close','recover']) {
    opportunity = await app.advance(operator, opportunity.id, operation, { version: opportunity.version, ...(operation === 'close' ? { status: 'won' } : {}) }, randomUUID());
  }
  const target = opportunity.recovery.actions[0].leakId;
  opportunity = await app.approval(approver, opportunity.id, 'recovery', target, { version: opportunity.version, decision: 'approve', reason: 'Synthetic demo only', expiresAt: new Date(Date.now() + 60000).toISOString() }, randomUUID());
  opportunity = await app.execute(operator, opportunity.id, 'recovery', target, { version: opportunity.version }, randomUUID());
  await app.advance(operator, opportunity.id, 'measure', { version: opportunity.version, record: { ...record, calls: { ...record.calls, missedCallsMonthly: 2 } } }, randomUUID());
  console.log(JSON.stringify(await app.dashboard(operator), null, 2));
} finally {
  await store.close();
  await rm(directory, { recursive: true, force: true });
}
