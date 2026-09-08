import path from 'node:path';
import { createHash, timingSafeEqual } from 'node:crypto';
import { ensure } from './errors.js';
import { fields, identifier, choice, string } from './validation.js';
import { vapiBindings } from './vapi.js';

export const ROLES = ['viewer', 'operator', 'approver', 'admin', 'intake'];
export function loadConfig(env = process.env) {
  ensure(env.REVENUE_ENABLED === 'true', 'CONFIG', 'Set REVENUE_ENABLED=true to start the isolated service', 503);
  ensure(['sandbox', 'test'].includes(env.REVENUE_ENV) && env.NODE_ENV !== 'production', 'CONFIG', 'Revenue Engine supports sandbox/test only', 503);
  const directory = path.resolve(env.REVENUE_DATA_DIR || 'revenue-engine-data');
  ensure(path.basename(directory) === 'revenue-engine-data', 'CONFIG', 'REVENUE_DATA_DIR must name a dedicated revenue-engine-data directory', 503);
  const host = env.REVENUE_HOST || '127.0.0.1';
  ensure(['127.0.0.1', '::1'].includes(host), 'CONFIG', 'Bind only to a loopback address', 503);
  const port = Number(env.REVENUE_PORT || 4311);
  ensure(Number.isInteger(port) && port >= 1024 && port <= 65535, 'CONFIG', 'Invalid REVENUE_PORT', 503);
  let entries;
  try { entries = JSON.parse(env.REVENUE_AUTH || 'null'); } catch { ensure(false, 'CONFIG', 'REVENUE_AUTH must be a JSON array', 503); }
  ensure(Array.isArray(entries) && entries.length > 0 && entries.length <= 100, 'CONFIG', 'Configure 1–100 Revenue Engine credentials', 503);
  const tokenHashes = new Set();
  const principals = entries.map(entry => {
    fields(entry, ['token', 'tenantId', 'actorId', 'role', 'tier'], ['token', 'tenantId', 'actorId', 'role', 'tier']);
    string(entry.token, 'token', 256);
    ensure(entry.token.length >= 32, 'CONFIG', 'Tokens must have at least 32 characters', 503);
    identifier(entry.tenantId, 'tenantId'); identifier(entry.actorId, 'actorId');
    choice(entry.role, ROLES, 'role'); choice(entry.tier, ['disabled', 'growth', 'pro', 'enterprise'], 'tier');
    const digest = createHash('sha256').update(entry.token).digest();
    ensure(!tokenHashes.has(digest.toString('hex')), 'CONFIG', 'Tokens must be unique', 503);
    tokenHashes.add(digest.toString('hex'));
    return { digest, tenantId: entry.tenantId, actorId: entry.actorId, role: entry.role, tier: entry.tier };
  });
  let bindings;
  try { bindings = JSON.parse(env.REVENUE_VAPI_BINDINGS || '[]'); } catch { ensure(false, 'CONFIG', 'Invalid REVENUE_VAPI_BINDINGS JSON', 503); }
  return { directory, host, port, principals, environment: env.REVENUE_ENV, vapiBindings: vapiBindings(bindings) };
}

export function authenticate(header, principals) {
  const token = typeof header === 'string' && header.startsWith('Bearer ') ? header.slice(7) : '';
  const digest = createHash('sha256').update(token).digest();
  let match;
  for (const principal of principals) if (timingSafeEqual(digest, principal.digest)) match = principal;
  ensure(match, 'UNAUTHORIZED', 'Authentication required', 401);
  const { digest: _, ...actor } = match;
  return actor;
}
