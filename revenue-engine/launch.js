import { randomBytes } from 'node:crypto';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { startRevenueServer } from './server.js';

export function launcherEnvironment(env = process.env) {
  if (env.NODE_ENV === 'production') throw new Error('Launch from a non-production environment.');
  const token = randomBytes(32).toString('hex');
  return { token, env: {
    ...env, REVENUE_ENABLED: 'true', REVENUE_ENV: 'sandbox', REVENUE_HOST: '127.0.0.1',
    REVENUE_DATA_DIR: env.REVENUE_DATA_DIR || fileURLToPath(new URL('../revenue-engine-data', import.meta.url)),
    REVENUE_AUTH: JSON.stringify([{token, tenantId:'local-workspace',actorId:'local-owner',role:'admin',tier:'enterprise'}]),
  }};
}
export async function launch({ open = true, env = process.env } = {}) {
  const settings = launcherEnvironment(env);
  const runtime = await startRevenueServer(settings.env);
  const address = 'http://127.0.0.1:' + runtime.server.address().port;
  const url = address + '/#key=' + settings.token;
  console.log('Revenue Engine is ready at ' + address);
  console.log('Keep this window open. Press Ctrl+C here to stop safely. Records persist between launches.');
  if (open) {
    // The URL is built only from a validated port and a random hexadecimal key.
    // A fragment never reaches the HTTP server; the browser removes it immediately.
    const args = process.platform === 'win32'
      ? ['powershell.exe', ['-NoProfile','-NonInteractive','-Command', "Start-Process '" + url + "'"]]
      : process.platform === 'darwin' ? ['open',[url]] : ['xdg-open',[url]];
    const child = spawn(args[0], args[1], { stdio:'ignore', windowsHide:true });
    child.on('error', () => console.error('Could not open your browser. Restart with a desktop browser available.'));
  }
  return { ...runtime, url };
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  launch().then(runtime => {
    for (const signal of ['SIGINT','SIGTERM']) process.once(signal, () => runtime.stop().catch(() => { process.exitCode = 1; }));
  }).catch(error => { console.error('Unable to start Revenue Engine: ' + (error.code || error.message)); process.exitCode = 1; });
}
