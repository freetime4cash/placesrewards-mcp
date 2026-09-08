import { ensure, RevenueError } from './errors.js';

export function localClient(env = process.env, transport = fetch) {
  const port = Number(env.REVENUE_PORT || 4311);
  ensure(Number.isInteger(port) && port >= 1024 && port <= 65535, 'CONFIG', 'Invalid Revenue Engine port', 503);
  const token = env.REVENUE_CLIENT_TOKEN;
  ensure(typeof token === 'string' && token.length >= 32 && !/[\r\n]/.test(token), 'CONFIG', 'Set REVENUE_CLIENT_TOKEN to a configured local credential', 503);
  return async (route, body, key) => {
    ensure(/^\/v1\/[a-zA-Z0-9/_:.-]+$/.test(route), 'VALIDATION', 'Invalid local route');
    const response = await transport(`http://127.0.0.1:${port}${route}`, { method: body === undefined ? 'GET' : 'POST', redirect: 'error', signal: AbortSignal.timeout(15000),
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...(key ? { 'Idempotency-Key': key } : {}) },
      ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
    const result = await boundedJson(response, 4 * 1024 * 1024);
    if (!response.ok || result.ok !== true) throw new RevenueError(typeof result.error?.code === 'string' ? result.error.code : 'LOCAL_REQUEST_FAILED', 'Local Revenue Engine request failed; inspect the error code and current state', response.status);
    return result.data;
  };
}
export async function boundedJson(response, maxBytes) {
  ensure(Number(response.headers.get('content-length') || 0) <= maxBytes, 'RESPONSE_TOO_LARGE', 'Response exceeded its size limit', 502);
  ensure(response.body, 'INVALID_RESPONSE', 'Empty response', 502);
  const reader = response.body.getReader(); const chunks = []; let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read(); if (done) break;
      size += value.length; ensure(size <= maxBytes, 'RESPONSE_TOO_LARGE', 'Response exceeded its size limit', 502); chunks.push(value);
    }
    try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); }
    catch { throw new RevenueError('INVALID_RESPONSE', 'Response was not valid JSON', 502); }
  } finally { await reader.cancel().catch(() => {}); reader.releaseLock(); }
}
