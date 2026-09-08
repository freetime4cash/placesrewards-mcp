import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

test('operational entrypoint imports only Revenue Engine modules and Node builtins', async () => {
  const seen = new Set();
  async function visit(url) {
    if (seen.has(url.href)) return;
    seen.add(url.href);
    assert.ok(url.href.startsWith(new URL('./', import.meta.url).href));
    const source = await readFile(url, 'utf8');
    assert.doesNotMatch(source, /\b(?:fetch|eval|require)\s*\(|\bimport\s*\(|node:(?:child_process|https|net|tls)|PLACESREWARDS_|SERVER_CONTROL_TOKEN|CRON_SECRET/);
    for (const match of source.matchAll(/^import\s+[^;]*?\sfrom\s+['"]([^'"]+)['"]/gm)) {
      if (!match[1].startsWith('node:')) await visit(new URL(match[1], url));
    }
  }
  await visit(new URL('./server.js', import.meta.url));
  assert.ok(seen.size >= 10);
  const production = await readFile(new URL('../server.js', import.meta.url), 'utf8');
  assert.doesNotMatch(production, /revenue-engine/);
  const pkg = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));
  assert.equal(pkg.scripts.start, 'node server.js');
  assert.equal(pkg.scripts.worker, 'node worker.js');
});
