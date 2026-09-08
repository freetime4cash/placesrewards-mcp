import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, writeFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fixture, operator, approver, baseline, key, recovering, approvalBody } from './test-support.js';
import { FileOpportunityStore } from './store.js';
import { ConnectorRegistry } from './connectors.js';

test('single writer excludes a second instance; transactions serialize without lost updates', async t => {
  const { store, directory } = await fixture(t);
  await assert.rejects(new FileOpportunityStore(directory).open(), { code: 'STORE_LOCKED' });
  await Promise.all(Array.from({ length: 20 }, (_, index) => store.transaction(state => { state.audit.push({ index }); })));
  assert.equal((await store.read()).audit.length, 20);
  assert.equal((await store.read()).revision, 20);
  assert.equal((await readdir(directory)).some(name => name.endsWith('.tmp')), false);
});

test('failed transaction rolls back memory and disk and leaves service usable', async t => {
  const { store, directory } = await fixture(t);
  const before = await readFile(path.join(directory, 'state.json'), 'utf8');
  await assert.rejects(store.transaction(state => { state.audit.push({ bad: true }); throw new Error('reject'); }));
  assert.equal((await store.read()).audit.length, 0);
  assert.equal(await readFile(path.join(directory, 'state.json'), 'utf8'), before);
  await store.transaction(state => { state.audit.push({ good: true }); });
});

test('corrupt and unsupported snapshots fail closed without overwriting data', async t => {
  const { store, directory } = await fixture(t);
  await store.close();
  for (const content of ['{broken', JSON.stringify({ schemaVersion: 999 }), JSON.stringify({ schemaVersion: 1, service: 'places-rewards-production' })]) {
    await writeFile(path.join(directory, 'state.json'), content);
    await assert.rejects(new FileOpportunityStore(directory).open(), { code: 'STORE_CORRUPT' });
    assert.equal(await readFile(path.join(directory, 'state.json'), 'utf8'), content);
  }
});

test('capacity errors leave the last committed opportunity available', async t => {
  const { app } = await fixture(t, { storeOptions: { maxOpportunities: 1 } });
  await app.create(operator, { records: [baseline] }, key());
  await assert.rejects(app.create(operator, { records: [{ ...baseline, id: 'second' }] }, key()), { code: 'STORE_CAPACITY' });
  assert.equal((await app.list(operator)).total, 1);
});

test('failed durable intent never invokes the execution connector', async t => {
  let calls = 0;
  const connectors = new ConnectorRegistry({ execution: { id: 'counter', mode: 'sandbox', execute() { calls++; throw new Error('must not be reached'); } } });
  const { app, store } = await fixture(t, { connectors });
  let o = await recovering(app);
  const target = o.recovery.actions[0].leakId;
  o = await app.approval(approver, o.id, 'recovery', target, approvalBody(o.version), key());
  const persist = store.persist.bind(store); store.persist = async () => { throw new Error('disk full'); };
  await assert.rejects(app.execute(operator, o.id, 'recovery', target, { version: o.version }, key()));
  assert.equal(calls, 0);
  store.persist = persist;
  assert.equal((await app.read(operator, o.id)).recovery.actions[0].status, 'approved');
});
