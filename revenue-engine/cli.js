import { readFile, stat } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { localClient } from './local-client.js';
import { ensure, RevenueError } from './errors.js';
import { identifier } from './validation.js';

export async function runCli(args, request) {
  if (args[0] === 'help' || args.length === 0) return { commands: ['status', 'callbacks', 'callback ID', 'create JSON_FILE KEY', 'approve ID JSON_FILE KEY', 'cancel ID JSON_FILE KEY', 'defer ID JSON_FILE KEY', 'outcome ID JSON_FILE KEY'], notes: 'JSON bodies must include the current callback version. Reuse the same KEY after a lost response. No command places calls or sends SMS.' };
  if (args.length === 1 && args[0] === 'status') return request('/v1/callbacks/summary');
  if (args.length === 1 && args[0] === 'callbacks') return request('/v1/callbacks');
  if (args.length === 2 && args[0] === 'callback') { identifier(args[1]); return request(`/v1/callbacks/${args[1]}`); }
  const create = args[0] === 'create';
  ensure(create ? args.length === 3 : args.length === 4 && ['approve','cancel','defer','outcome'].includes(args[0]), 'USAGE', 'Run revenue -- help for commands');
  const file = args[create ? 1 : 2], key = args[create ? 2 : 3]; identifier(key, 'request key');
  if (!create) identifier(args[1]);
  const info = await stat(file); ensure(info.isFile() && info.size <= 256 * 1024, 'IMPORT_SIZE', 'Command JSON must be at most 256 KiB');
  let body; try { body = JSON.parse(await readFile(file, 'utf8')); } catch { throw new RevenueError('INVALID_JSON', 'Invalid command JSON'); }
  return request(create ? '/v1/opportunities' : `/v1/callbacks/${args[1]}/${args[0]}`, body, key);
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const args = process.argv.slice(2);
  Promise.resolve().then(() => runCli(args, args.length === 0 || args[0] === 'help' ? null : localClient()))
    .then(result => console.log(JSON.stringify(result, null, 2)))
    .catch(error => { console.error(JSON.stringify({ ok: false, code: error instanceof RevenueError ? error.code : 'CLIENT_FAILED', message: error instanceof RevenueError ? error.message : 'Local request failed' })); process.exitCode = 1; });
}
