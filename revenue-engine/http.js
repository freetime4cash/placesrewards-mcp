import http from 'node:http';
import { randomUUID } from 'node:crypto';
import { authenticate } from './config.js';
import { RevenueError, ensure } from './errors.js';
import { fields } from './validation.js';

async function readJson(req, maxBytes) {
  ensure((req.headers['content-type'] || '').split(';')[0].trim() === 'application/json', 'CONTENT_TYPE', 'Use application/json', 415);
  ensure(!req.headers['content-encoding'], 'CONTENT_ENCODING', 'Compressed requests are not supported', 415);
  if (req.headers['content-length']) ensure(Number(req.headers['content-length']) <= maxBytes, 'BODY_TOO_LARGE', 'Request body too large', 413);
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    ensure(size <= maxBytes, 'BODY_TOO_LARGE', 'Request body too large', 413);
    chunks.push(chunk);
  }
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); }
  catch { throw new RevenueError('INVALID_JSON', 'Invalid JSON body', 400); }
}

/** Injected logger receives only these allowlisted fields, never tokens, bodies, or provider errors. */
export function createRevenueHttpServer({ app, principals, logger = entry => console.log(JSON.stringify(entry)), maxBodyBytes = 256 * 1024, maxConcurrent = 32 }) {
  let active = 0;
  const pending = new Set();
  const server = http.createServer({ maxHeaderSize: 16 * 1024, requestTimeout: 15000, headersTimeout: 10000, keepAliveTimeout: 5000 }, async (req, res) => {
    let finish;
    const completed = new Promise(resolve => { finish = resolve; });
    pending.add(completed);
    const requestId = randomUUID(), started = Date.now();
    let status = 500, code = 'INTERNAL', actor;
    active++;
    function send(value) {
      const data = JSON.stringify({ contractVersion: '1.0', requestId, ...value });
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(data), 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', 'X-Request-Id': requestId });
      res.end(data);
    }
    try {
      ensure(active <= maxConcurrent, 'BUSY', 'Too many concurrent requests', 429);
      ensure((req.url || '').length < 4096, 'URI_TOO_LONG', 'Request URL too long', 414);
      const url = new URL(req.url, 'http://localhost');
      if (req.method === 'GET' && url.pathname === '/health') {
        await app.store.read(); status = 200; code = 'OK'; send({ ok: true, service: 'revenue-engine', mode: 'sandbox', externalMutation: false }); return;
      }
      actor = authenticate(req.headers.authorization, principals);
      app.authorize(actor, ['viewer','operator','approver','admin']);
      let parts;
      try { parts = url.pathname.split('/').filter(Boolean).map(decodeURIComponent); }
      catch { throw new RevenueError('INVALID_URL', 'Invalid URL encoding'); }
      ensure(parts[0] === 'v1', 'NOT_FOUND', 'Endpoint not found', 404);
      const query = Object.fromEntries(url.searchParams);
      ensure([...url.searchParams.keys()].length === Object.keys(query).length, 'VALIDATION', 'Duplicate query parameter');
      let data;
      if (req.method === 'GET') {
        if (parts.length === 2 && ['opportunities','prospects'].includes(parts[1])) data = await app.list(actor, query);
        else if (parts.length === 2 && parts[1] === 'dashboard') { fields(query, []); data = await app.dashboard(actor); }
        else if (parts.length === 2 && parts[1] === 'providers') { fields(query, []); data = app.connectors.describe(); }
        else if (parts.length === 2 && parts[1] === 'audit') {
          fields(query, ['offset','limit']);
          data = await app.auditLog(actor, { offset: query.offset === undefined ? 0 : Number(query.offset), limit: query.limit === undefined ? 50 : Number(query.limit) });
        } else if (parts[1] === 'opportunities' && parts.length >= 3 && parts.length <= 4) {
          fields(query, []);
          ensure(parts.length === 3 || ['report','evidence'].includes(parts[3]), 'NOT_FOUND', 'Endpoint not found', 404);
          data = await app.read(actor, parts[2], parts[3]);
        } else throw new RevenueError('NOT_FOUND', 'Endpoint not found', 404);
      } else if (req.method === 'POST') {
        fields(query, []);
        const body = await readJson(req, maxBodyBytes);
        const key = req.headers['idempotency-key'];
        if (parts.length === 2 && parts[1] === 'opportunities') data = await app.create(actor, body, key);
        else if (parts.length === 2 && parts[1] === 'discover') data = await app.create(actor, body, key, { discover: true });
        else if (parts.length === 4 && parts[1] === 'providers' && parts[3] === 'import') data = await app.create(actor, body, key, { discover: true, provider: parts[2] });
        else if (parts[1] === 'opportunities' && parts.length === 4) {
          if (parts[3] === 'queue') data = await app.queue(actor, parts[2], body, key);
          else if (parts[3] === 'outreach') data = await app.draftOutreach(actor, parts[2], body, key);
          else if (['diagnose','quantify','prescribe','demo','close','reopen','recover','measure'].includes(parts[3])) data = await app.advance(actor, parts[2], parts[3], body, key);
          else throw new RevenueError('NOT_FOUND', 'Endpoint not found', 404);
        } else if (parts[1] === 'opportunities' && parts.length === 6 && ['recovery','outreach'].includes(parts[3])) {
          const args = [actor, parts[2], parts[3], parts[4], body, key];
          if (parts[5] === 'approval') data = await app.approval(...args);
          else if (parts[5] === 'execute') data = await app.execute(...args);
          else if (parts[5] === 'reconcile') data = await app.reconcile(...args);
          else if (parts[3] === 'outreach' && parts[5] === 'outcome') data = await app.outreachOutcome(actor, parts[2], parts[4], body, key);
          else throw new RevenueError('NOT_FOUND', 'Endpoint not found', 404);
        } else throw new RevenueError('NOT_FOUND', 'Endpoint not found', 404);
      } else throw new RevenueError('METHOD_NOT_ALLOWED', 'Use GET or POST', 405);
      status = 200; code = 'OK'; send({ ok: true, data });
    } catch (error) {
      status = error instanceof RevenueError ? error.status : 500;
      code = error instanceof RevenueError ? error.code : 'INTERNAL';
      if (!res.headersSent && !res.destroyed) send({ ok: false, error: { code, message: error instanceof RevenueError ? error.message : 'Internal failure; inspect service health and request ID' } });
      req.resume();
    } finally {
      active--;
      pending.delete(completed); finish();
      try { logger({ event: 'revenue.request', requestId, status, code, durationMs: Date.now() - started, tenantId: actor?.tenantId, actorId: actor?.actorId }); } catch { /* logging must not repeat a committed operation */ }
    }
  });
  server.drain = () => Promise.all([...pending]);
  return server;
}
