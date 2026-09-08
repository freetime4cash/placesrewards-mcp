import { pathToFileURL } from 'node:url';
import { loadConfig } from './config.js';
import { FileOpportunityStore } from './store.js';
import { RevenueApplication } from './application.js';
import { createRevenueHttpServer } from './http.js';

export async function startRevenueServer(env = process.env) {
  const config = loadConfig(env);
  const store = await new FileOpportunityStore(config.directory).open();
  try {
    const app = new RevenueApplication({ store });
    await app.recoverInterruptedExecutions();
    const server = createRevenueHttpServer({ app, principals: config.principals });
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(config.port, config.host, () => { server.off('error', reject); resolve(); });
    });
    let stopping;
    const stop = () => stopping ||= (async () => {
      // Drain in-flight execution receipts before releasing the single-writer lock.
      await new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
      await server.drain();
      await store.close();
    })();
    return { server, store, app, stop };
  } catch (error) { await store.close(); throw error; }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  startRevenueServer().then(runtime => {
    console.log(JSON.stringify({ event: 'revenue.started', mode: 'sandbox', address: runtime.server.address() }));
    for (const signal of ['SIGINT','SIGTERM']) process.once(signal, () => {
      runtime.stop().catch(() => { console.error(JSON.stringify({ event: 'revenue.shutdown_failed' })); process.exitCode = 1; });
    });
  }).catch(error => {
    console.error(JSON.stringify({ event: 'revenue.start_failed', code: error.code || 'START_FAILED' }));
    process.exitCode = 1;
  });
}
