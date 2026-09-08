import test from 'node:test';
import assert from 'node:assert/strict';
import { businessRecord } from './validation.js';
import { loadConfig, authenticate } from './config.js';
import { baseline } from './test-support.js';
import { evidenceQuality } from './evidence.js';
import { RevenueSignalAggregator } from './providers.js';
import { ConnectorRegistry } from './connectors.js';

const env = { REVENUE_ENABLED: 'true', REVENUE_ENV: 'sandbox', REVENUE_AUTH: JSON.stringify([{ token: 'test'.repeat(10), tenantId: 'test', actorId: 'test', role: 'admin', tier: 'enterprise' }]) };
test('configuration fails closed for disabled, production, remote binding and unsafe data directory', () => {
  for (const overrides of [{ REVENUE_ENABLED: undefined }, { REVENUE_ENV: 'production' }, { NODE_ENV: 'production' }, { REVENUE_HOST: '0.0.0.0' }, { REVENUE_DATA_DIR: 'data' }, { REVENUE_AUTH: '{}' }, { REVENUE_AUTH: '[' }, { REVENUE_PORT: 'NaN' }]) assert.throws(() => loadConfig({ ...env, ...overrides }));
  const config = loadConfig(env);
  assert.equal(config.host, '127.0.0.1');
  assert.equal(authenticate('Bearer ' + 'test'.repeat(10), config.principals).tenantId, 'test');
  assert.throws(() => authenticate('Bearer wrong', config.principals), { code: 'UNAUTHORIZED' });
  assert.equal('token' in config.principals[0], false);
  const entries = JSON.parse(env.REVENUE_AUTH);
  assert.throws(() => loadConfig({ ...env, REVENUE_AUTH: JSON.stringify([...entries, ...entries]) }));
  assert.throws(() => new ConnectorRegistry({ execution: { mode: 'production', execute() {} } }), { code: 'UNSAFE_CONNECTOR' });
});

test('record validation rejects malformed or unbounded inputs, evidence timestamps and unknown fields', () => {
  for (const input of [null, [], {}, { ...baseline, id: '../escape' }, { ...baseline, averageTicket: Infinity }, { ...baseline, metrics: { dormant_customers: -1 } }, { ...baseline, metrics: { lead_close_rate: 2 } }, { ...baseline, calls: { missedCallsMonthly: '20' } }, { ...baseline, metrics: { unsupported: true } }, { ...baseline, observedAt: 'invalid' }, { ...baseline, observedAt: '2999-01-01T00:00:00Z' }, { ...baseline, website: { hasBooking: 'false' } }, { ...baseline, entitlement: { tier: 'enterprise' } }]) assert.throws(() => businessRecord(input), { code: 'VALIDATION' });
  assert.equal(businessRecord(baseline).id, baseline.id);
});

test('missing booleans stay unknown, invalid timestamps cannot poison quality and newest signals win', () => {
  const aggregator = new RevenueSignalAggregator();
  const record = aggregator.enrich({ publicProfile: { reviewRating: 4 }, website: { hasBooking: false } });
  assert.deepEqual(record.signals.filter(s => s.key.startsWith('website_')).map(s => [s.key, s.value]), [['website_has_booking', false]]);
  assert.equal(evidenceQuality([{ key: 'x', observedAt: 'bad' }]).freshness, 0);
  const merged = aggregator.enrich({ observedAt: '2025-01-01T00:00:00Z', metrics: { missed_calls_monthly: 1 }, calls: { observedAt: '2024-12-31T20:00:00-05:00', missedCallsMonthly: 9 } });
  assert.equal(merged.signals[0].value, 9);
});
