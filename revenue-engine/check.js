import { readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('./', import.meta.url));
const files = readdirSync(root).filter(name => name.endsWith('.js')).sort();
const browserFiles = readdirSync(root + 'web').filter(name => name.endsWith('.js')).map(name => 'web/' + name);
for (const file of [...files, ...browserFiles]) {
  const result = spawnSync(process.execPath, ['--check', root + file], { stdio: 'inherit' });
  if (result.status !== 0) process.exit(result.status || 1);
}
const tests = files.filter(name => name === 'test.js' || name.endsWith('.test.js'));
const result = spawnSync(process.execPath, ['--test', ...tests.map(name => root + name)], { stdio: 'inherit' });
process.exit(result.status || (result.error ? 1 : 0));
