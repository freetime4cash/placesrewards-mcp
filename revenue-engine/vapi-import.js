import { readFile, stat } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { callToEvent, normalizeVapiEvent, vapiBindings } from './vapi.js';
import { localClient, boundedJson } from './local-client.js';
import { ensure, RevenueError } from './errors.js';

/** One-shot, read-only Vapi pull. No cursor is advanced and no outbound call is ever created. */
export async function importVapi({ env = process.env, file, transport = fetch, client } = {}) {
  const request = client || localClient(env, transport);
  let bindings;
  try { bindings = vapiBindings(JSON.parse(env.REVENUE_VAPI_BINDINGS || '[]')); } catch { throw new RevenueError('CONFIG', 'Invalid Vapi bindings', 503); }
  ensure(bindings.length > 0, 'CONFIG', 'Configure Vapi assistant/number bindings first', 503);
  let input;
  if (file) {
    const info = await stat(file); ensure(info.isFile() && info.size <= 4 * 1024 * 1024, 'IMPORT_SIZE', 'Import requires a JSON file up to 4 MiB');
    try { input = JSON.parse(await readFile(file, 'utf8')); } catch { throw new RevenueError('INVALID_JSON', 'Invalid import JSON'); }
  } else {
    ensure(typeof env.REVENUE_VAPI_API_KEY === 'string' && env.REVENUE_VAPI_API_KEY.length >= 16 && !/[\r\n]/.test(env.REVENUE_VAPI_API_KEY), 'CONFIG', 'Set a private Vapi API key locally', 503);
    const response = await transport('https://api.vapi.ai/call?limit=100', { method: 'GET', redirect: 'error', signal: AbortSignal.timeout(15000), headers: { Authorization: `Bearer ${env.REVENUE_VAPI_API_KEY}` } });
    ensure(response.ok, 'VAPI_READ_FAILED', 'Vapi read failed; verify credentials or quota before retrying', 502);
    input = await boundedJson(response, 4 * 1024 * 1024);
    ensure(Array.isArray(input), 'VAPI_FORMAT', 'Unexpected Vapi list response', 502);
    ensure(input.length < 100, 'VAPI_BATCH_FULL', 'Vapi batch is full; use a complete call export instead of assuming all calls were imported', 409);
  }
  const entries = Array.isArray(input) ? input : [input];
  ensure(entries.length <= 1000, 'IMPORT_SIZE', 'At most 1000 calls per file');
  // Validate everything before posting. Individual event commits remain safely replayable after partial failure.
  const events = [];
  for (const entry of entries) {
    const event = entry?.message ? entry : callToEvent(entry);
    if (!event) continue;
    // Filter other assistants before parsing their customer data.
    const call = event.message?.call;
    if (!bindings.some(b => b.assistantId === call?.assistantId && b.phoneNumberId === call?.phoneNumberId)) continue;
    const normalized = normalizeVapiEvent(event);
    if (!normalized.ignored) events.push({ message: { type: 'end-of-call-report',
      call: { id: normalized.callId, type: 'inboundPhoneCall', assistantId: normalized.assistantId, phoneNumberId: normalized.phoneNumberId,
        endedAt: normalized.endedAt, customer: { number: normalized.phone, name: normalized.name } },
      endedReason: normalized.endedReason, analysis: { summary: normalized.summary, structuredData: { callbackRequested: normalized.callbackRequested } } } });
  }
  const result = { inspected: entries.length, eligible: events.length, imported: 0, duplicates: 0, ignored: entries.length - events.length, externalMutation: false };
  for (const event of events) {
    const response = await request('/v1/integrations/vapi/events', event);
    if (response.duplicate) result.duplicates++; else result.imported++;
  }
  return result;
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  const valid = args.length === 0 || (args.length === 2 && args[0] === '--file');
  (async () => { ensure(valid, 'USAGE', 'Use sync:vapi or sync:vapi -- --file calls.json'); return importVapi({ file: args[1] }); })()
    .then(result => console.log(JSON.stringify(result, null, 2)))
    .catch(error => { console.error(JSON.stringify({ ok: false, code: error instanceof RevenueError ? error.code : 'IMPORT_FAILED', message: error instanceof RevenueError ? error.message : 'Import failed; retain the source and retry after checking local configuration' })); process.exitCode = 1; });
}
