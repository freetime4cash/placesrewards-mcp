import { promises as fs } from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { ensure, RevenueError } from './errors.js';

const empty = () => ({ schemaVersion: 1, service: 'revenue-engine-sandbox', revision: 0, opportunities: {}, commands: {}, audit: [] });
const plain = value => value !== null && typeof value === 'object' && !Array.isArray(value);
function validate(state) {
  ensure(state?.schemaVersion === 1 && state.service === 'revenue-engine-sandbox' && Number.isSafeInteger(state.revision) && state.revision >= 0 && plain(state.opportunities) && plain(state.commands) && Array.isArray(state.audit), 'STORE_CORRUPT', 'Invalid or unsupported Revenue Engine store', 503);
  if (state.smsSuppressions !== undefined) ensure(plain(state.smsSuppressions) && Object.values(state.smsSuppressions).every(item => typeof item?.tenantId === 'string' && typeof item.eventId === 'string'), 'STORE_CORRUPT', 'Invalid SMS suppression state', 503);
  if (state.callbackSuppressions !== undefined) ensure(plain(state.callbackSuppressions) && Object.values(state.callbackSuppressions).every(item => typeof item?.tenantId === 'string' && typeof item.callbackId === 'string'), 'STORE_CORRUPT', 'Invalid callback suppressions', 503);
  if (state.callbacks !== undefined) {
    ensure(plain(state.callbacks), 'STORE_CORRUPT', 'Invalid callback queue', 503);
    for (const [id, task] of Object.entries(state.callbacks)) ensure(task?.id === id && typeof task.tenantId === 'string' && state.opportunities[task.opportunityId]?.tenantId === task.tenantId && Number.isSafeInteger(task.version) && task.version > 0 && ['pending_approval','approved','completed','cancelled'].includes(task.status) && plain(task.call) && Array.isArray(task.attempts) && Array.isArray(task.history) && Array.isArray(task.approvalHistory), 'STORE_CORRUPT', 'Invalid callback record', 503);
  }
  for (const [id, value] of Object.entries(state.opportunities)) {
    ensure(value?.id === id && typeof value.tenantId === 'string' && Number.isSafeInteger(value.version) && value.version > 0 && Array.isArray(value.history) && value.business?.id && ['discovered','diagnosed','quantified','prescribed','demonstrated','closed','recovering','measured'].includes(value.stage), 'STORE_CORRUPT', 'Invalid opportunity in store', 503);
    ensure(['growth','pro','enterprise'].includes(value.tier) && Array.isArray(value.business.signals) && Array.isArray(value.outreach) && plain(value.queue) && ['ready','claimed','snoozed','done'].includes(value.queue.status) && Number.isFinite(value.score), 'STORE_CORRUPT', 'Invalid opportunity data', 503);
    if (value.stage !== 'discovered') ensure(plain(value.report) && Array.isArray(value.report.leaks) && Number.isFinite(value.report.totalEstimatedMonthlyLoss), 'STORE_CORRUPT', 'Invalid diagnosis', 503);
    if (['closed','recovering','measured'].includes(value.stage)) ensure(['won','lost','deferred'].includes(value.close?.status), 'STORE_CORRUPT', 'Invalid close outcome', 503);
    if (['recovering','measured'].includes(value.stage)) ensure(Array.isArray(value.recovery?.actions), 'STORE_CORRUPT', 'Invalid recovery state', 503);
    for (const action of [...(value.recovery?.actions || []), ...value.outreach]) {
      ensure(plain(action) && ['draft','pending_approval','approved','executing','uncertain','simulated'].includes(action.status), 'STORE_CORRUPT', 'Invalid action state', 503);
      if (action.status === 'approved') ensure(action.approved === true && typeof action.approval?.payloadHash === 'string' && Number.isFinite(Date.parse(action.approval.expiresAt)), 'STORE_CORRUPT', 'Invalid approval state', 503);
      if (['executing','uncertain'].includes(action.status)) ensure(typeof action.execution?.id === 'string', 'STORE_CORRUPT', 'Missing execution intent', 503);
    }
  }
}

/** Single writer, local filesystem only. Owns an exclusive lifetime lock; never steals stale locks. */
export class FileOpportunityStore {
  constructor(directory, { maxBytes = 32 * 1024 * 1024, maxOpportunities = 10000 } = {}) {
    this.directory = path.resolve(directory);
    this.maxBytes = maxBytes;
    this.maxOpportunities = maxOpportunities;
    this.tail = Promise.resolve();
    this.closed = true;
  }
  async open() {
    ensure(!this.lock, 'STORE_LOCKED', 'Store is already open', 503);
    await fs.mkdir(this.directory, { recursive: true, mode: 0o700 });
    const info = await fs.lstat(this.directory);
    ensure(info.isDirectory() && !info.isSymbolicLink(), 'STORE_UNSAFE', 'Store directory must not be a link', 503);
    this.file = path.join(this.directory, 'state.json');
    this.lockPath = path.join(this.directory, 'writer.lock');
    try { this.lock = await fs.open(this.lockPath, 'wx', 0o600); }
    catch (error) {
      if (error.code === 'EEXIST') throw new RevenueError('STORE_LOCKED', 'Store is locked; inspect its owner before offline recovery', 503);
      throw error;
    }
    try {
      await this.lock.writeFile(JSON.stringify({ pid: process.pid, startedAt: new Date().toISOString() }));
      await this.lock.sync();
      try {
        const stat = await fs.lstat(this.file);
        ensure(stat.isFile() && !stat.isSymbolicLink() && stat.size <= this.maxBytes, 'STORE_CORRUPT', 'Unsafe or oversized store', 503);
        this.state = JSON.parse(await fs.readFile(this.file, 'utf8'));
        validate(this.state);
      } catch (error) {
        if (error.code !== 'ENOENT') throw new RevenueError('STORE_CORRUPT', 'Store could not be validated; no data was replaced', 503);
        this.state = empty();
        await this.persist(this.state);
      }
      this.closed = false;
      return this;
    } catch (error) { await this.releaseLock(); throw error; }
  }
  async persist(state) {
    validate(state);
    ensure(Object.keys(state.opportunities).length <= this.maxOpportunities, 'STORE_CAPACITY', 'Opportunity capacity reached', 507);
    const data = JSON.stringify(state);
    ensure(Buffer.byteLength(data) <= this.maxBytes, 'STORE_CAPACITY', 'Store capacity reached; archive offline before continuing', 507);
    const temp = path.join(this.directory, `state-${randomUUID()}.tmp`);
    let handle;
    try {
      handle = await fs.open(temp, 'wx', 0o600);
      await handle.writeFile(data);
      await handle.sync();
      await handle.close(); handle = null;
      await fs.rename(temp, this.file);
      // Windows does not expose directory fsync through Node; file fsync + rename still applies.
      if (process.platform !== 'win32') {
        const dir = await fs.open(this.directory, 'r');
        try { await dir.sync(); } finally { await dir.close(); }
      }
    } catch (error) {
      // A rename may have committed before a sync failed: fail closed until reopened.
      this.faulted = true;
      throw error;
    } finally {
      if (handle) await handle.close();
      await fs.unlink(temp).catch(error => { if (error.code !== 'ENOENT') throw error; });
    }
  }
  async transaction(change) {
    ensure(!this.closed && !this.faulted, 'STORE_UNAVAILABLE', 'Store is unavailable', 503);
    const run = this.tail.then(async () => {
      ensure(!this.faulted, 'STORE_UNAVAILABLE', 'Store is unavailable', 503);
      const next = structuredClone(this.state);
      const result = await change(next);
      next.revision++;
      await this.persist(next);
      // Keep in-memory and returned values identical to their persisted JSON representation.
      this.state = JSON.parse(JSON.stringify(next));
      return result === undefined ? undefined : JSON.parse(JSON.stringify(result));
    });
    this.tail = run.catch(() => {});
    return run;
  }
  async read() {
    await this.tail;
    ensure(!this.closed && !this.faulted, 'STORE_UNAVAILABLE', 'Store is unavailable', 503);
    return structuredClone(this.state);
  }
  async releaseLock() {
    if (this.lock) { await this.lock.close(); this.lock = null; await fs.unlink(this.lockPath); }
  }
  async close() {
    this.closed = true;
    await this.tail;
    await this.releaseLock();
  }
}
