import { createHash, randomUUID } from 'node:crypto';
import { entitlementFor } from './index.js';
import { RevenueDiscoveryPipeline } from './pipeline.js';
import { createOpportunity, diagnoseOpportunity, quantifyOpportunity, prescribeOpportunity, demonstrateOpportunity, closeOpportunity, beginRecovery, measureOpportunity, serializeOpportunity } from './workflow.js';
import { ConnectorRegistry } from './connectors.js';
import { ensure } from './errors.js';
import { businessRecord, choice, fields, identifier, number, string, timestamp } from './validation.js';
import { dashboard, opportunityReport } from './reporting.js';

function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (value !== null && typeof value === 'object') return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
  return JSON.stringify(value);
}
const hash = value => createHash('sha256').update(canonical(value)).digest('hex');
const iso = () => new Date().toISOString();
const actionsFor = (o, kind) => kind === 'recovery' ? o.recovery?.actions || [] : o.outreach;
const payloadFor = (action, kind) => kind === 'recovery'
  ? { leakId: action.leakId, category: action.category, route: action.route, action: action.action }
  : { channel: action.channel, recipient: action.recipient, subject: action.subject, body: action.body };
const actionId = action => action.id || action.leakId;

export class RevenueApplication {
  constructor({ store, connectors = new ConnectorRegistry(), executionTimeoutMs = 5000 }) {
    this.store = store; this.connectors = connectors; this.executionTimeoutMs = executionTimeoutMs;
    this.analysis = new RevenueDiscoveryPipeline({ tier: 'pro' });
  }
  authorize(actor, roles, capability = 'diagnostics', opportunity) {
    ensure(actor && typeof actor === 'object', 'UNAUTHORIZED', 'Authentication required', 401);
    identifier(actor.tenantId, 'tenantId'); identifier(actor.actorId, 'actorId');
    ensure(roles.includes(actor.role), 'FORBIDDEN', 'Role cannot perform this operation', 403);
    ensure(['disabled','growth','pro','enterprise'].includes(actor.tier), 'FORBIDDEN', 'Invalid entitlement', 403);
    ensure(entitlementFor(actor.tier).capabilities[capability], 'ENTITLEMENT', `Capability ${capability} is not enabled`, 403);
    if (opportunity) ensure(entitlementFor(opportunity.tier).capabilities[capability], 'ENTITLEMENT', `Opportunity does not include ${capability}`, 403);
  }
  get(state, actor, id) {
    const o = Object.hasOwn(state.opportunities, id) ? state.opportunities[id] : null;
    ensure(o && o.tenantId === actor.tenantId, 'NOT_FOUND', 'Opportunity not found', 404);
    return o;
  }
  stage(o, expected) { ensure(expected.includes(o.stage), 'STAGE_CONFLICT', `Operation requires stage ${expected.join(' or ')}`, 409); }
  present(actor, value) {
    const result = structuredClone(value);
    if (!entitlementFor(actor.tier).capabilities.advancedReporting) {
      for (const o of Array.isArray(result) ? result : [result]) if (o?.demonstration) o.demonstration.executiveReport = null;
    }
    return result;
  }
  audit(state, actor, operation, opportunityId, details = {}) {
    state.audit.push({ id: randomUUID(), at: iso(), tenantId: actor.tenantId, actorId: actor.actorId, operation, opportunityId, ...details });
  }
  async command(actor, operation, body, key, roles, apply, capability = 'diagnostics') {
    this.authorize(actor, roles, capability);
    identifier(key, 'Idempotency-Key');
    const commandId = hash([actor.tenantId, actor.actorId, key]);
    const fingerprint = hash({ operation, body });
    const result = await this.store.transaction(async state => {
      const previous = state.commands[commandId];
      if (previous) {
        ensure(previous.fingerprint === fingerprint, 'IDEMPOTENCY_CONFLICT', 'Key was already used for a different request', 409);
        return previous.response;
      }
      const response = await apply(state);
      state.commands[commandId] = { fingerprint, response, at: iso() };
      return response;
    });
    return this.present(actor, result);
  }
  async create(actor, body, key, { discover = false, provider = null } = {}) {
    fields(body, ['records'], ['records']);
    ensure(Array.isArray(body.records) && body.records.length > 0 && body.records.length <= (discover ? 100 : 1), 'VALIDATION', discover ? 'Provide 1–100 records' : 'Provide one record');
    const records = provider ? this.connectors.importRecords(provider, body) : body.records.map(record => businessRecord(record));
    ensure(new Set(records.map(record => record.id)).size === records.length, 'VALIDATION', 'Duplicate business IDs in request');
    return this.command(actor, `create:${discover}:${provider}`, body, key, ['operator','admin'], state => {
      const results = [];
      for (const record of records) {
        ensure(!Object.values(state.opportunities).some(o => o.tenantId === actor.tenantId && o.business.id === record.id), 'DUPLICATE_BUSINESS', 'Business already has an opportunity', 409);
        const enriched = this.analysis.analyze(record);
        let o = serializeOpportunity(createOpportunity(record, { tier: actor.tier, source: provider || (discover ? 'discovery' : 'manual') }));
        o = { ...o, id: randomUUID(), tenantId: actor.tenantId, version: 1, business: enriched.business,
          discoveryReport: enriched.report, score: enriched.opportunityScore, qualification: enriched.qualification, evidenceQuality: enriched.evidenceQuality,
          queue: { status: 'ready', owner: null, followUpAt: null, leaseUntil: null }, outreach: [] };
        state.opportunities[o.id] = o;
        this.audit(state, actor, 'discovered', o.id, { provider: provider || 'manual' });
        results.push(o);
      }
      return results.sort((a, b) => b.score - a.score || a.id.localeCompare(b.id));
    }, discover ? 'automatedDiscovery' : 'diagnostics');
  }
  async list(actor, query = {}) {
    this.authorize(actor, ['viewer','operator','approver','admin']);
    fields(query, ['stage','offset','limit','queue']);
    const offset = query.offset === undefined ? 0 : Number(query.offset);
    const limit = query.limit === undefined ? 50 : Number(query.limit);
    ensure(Number.isSafeInteger(offset) && offset >= 0 && Number.isSafeInteger(limit) && limit >= 1 && limit <= 100, 'VALIDATION', 'Invalid pagination');
    if (query.stage) choice(query.stage, ['discovered','diagnosed','quantified','prescribed','demonstrated','closed','recovering','measured'], 'stage');
    if (query.queue) choice(query.queue, ['ready','claimed','snoozed','done'], 'queue');
    const state = await this.store.read();
    const items = Object.values(state.opportunities).filter(o => o.tenantId === actor.tenantId && (!query.stage || o.stage === query.stage) && (!query.queue || o.queue.status === query.queue)).sort((a, b) => b.score - a.score || a.id.localeCompare(b.id));
    return { items: this.present(actor, items.slice(offset, offset + limit)), total: items.length, offset, limit, nextOffset: offset + limit < items.length ? offset + limit : null };
  }
  async read(actor, id, view = 'opportunity') {
    this.authorize(actor, ['viewer','operator','approver','admin'], view === 'report' ? 'advancedReporting' : 'diagnostics');
    const state = await this.store.read();
    const o = this.get(state, actor, id);
    if (view === 'report') { this.authorize(actor, ['viewer','operator','approver','admin'], 'advancedReporting', o); return opportunityReport(o); }
    if (view === 'evidence') return { opportunityId: id, version: o.version, signals: o.business.signals, quality: o.evidenceQuality };
    return this.present(actor, o);
  }
  async dashboard(actor) {
    this.authorize(actor, ['viewer','operator','approver','admin'], 'advancedReporting');
    const state = await this.store.read();
    return dashboard(Object.values(state.opportunities).filter(o => o.tenantId === actor.tenantId));
  }
  async auditLog(actor, { offset = 0, limit = 50 } = {}) {
    this.authorize(actor, ['approver','admin']);
    ensure(Number.isSafeInteger(offset) && offset >= 0 && Number.isSafeInteger(limit) && limit > 0 && limit <= 100, 'VALIDATION', 'Invalid pagination');
    const state = await this.store.read();
    const entries = state.audit.filter(entry => entry.tenantId === actor.tenantId);
    return { items: entries.slice(offset, offset + limit), total: entries.length, offset, limit };
  }
  async mutate(actor, id, operation, body, key, roles, change, capability = 'diagnostics') {
    number(body.version, 'version', 1, Number.MAX_SAFE_INTEGER);
    ensure(Number.isSafeInteger(body.version), 'VALIDATION', 'Version must be an integer');
    return this.command(actor, `${id}:${operation}`, body, key, roles, state => {
      let o = this.get(state, actor, id);
      this.authorize(actor, roles, capability, o);
      ensure(o.version === body.version, 'VERSION_CONFLICT', 'Reload the opportunity and use its current version', 409);
      // Stored entitlements are not an authority; rebuild from the server-assigned tier.
      o.entitlement = entitlementFor(o.tier);
      o = change(o);
      o.version++; o.updatedAt = iso();
      state.opportunities[id] = serializeOpportunity(o);
      this.audit(state, actor, operation, id, { version: o.version });
      return state.opportunities[id];
    }, capability);
  }
  async advance(actor, id, operation, body, key) {
    const extras = operation === 'close' ? ['status','agreedMonthlyFee','recoverySharePercent','notes'] : operation === 'measure' ? ['record'] : [];
    fields(body, ['version', ...extras], ['version', ...(operation === 'close' ? ['status'] : operation === 'measure' ? ['record'] : [])]);
    return this.mutate(actor, id, operation, body, key, ['operator','admin'], o => {
      switch (operation) {
        case 'diagnose': this.stage(o, ['discovered']); return diagnoseOpportunity({ ...o, _discoveryReport: o.discoveryReport });
        case 'quantify': this.stage(o, ['diagnosed']); return quantifyOpportunity(o);
        case 'prescribe': this.stage(o, ['quantified']); return prescribeOpportunity(o);
        case 'demo': this.stage(o, ['prescribed']); return demonstrateOpportunity(o);
        case 'close': {
          this.stage(o, ['demonstrated']); choice(body.status, ['won','lost','deferred'], 'close status');
          if (body.agreedMonthlyFee !== undefined) number(body.agreedMonthlyFee, 'agreedMonthlyFee');
          if (body.recoverySharePercent !== undefined) number(body.recoverySharePercent, 'recoverySharePercent', 0, 100);
          if (body.notes !== undefined) string(body.notes, 'notes', 4000);
          const result = closeOpportunity(o, body);
          result.queue = { ...o.queue, status: body.status === 'deferred' ? 'snoozed' : 'done', owner: null, leaseUntil: null };
          return result;
        }
        case 'reopen': {
          this.stage(o, ['closed']); ensure(o.close.status === 'deferred', 'STAGE_CONFLICT', 'Only deferred deals can reopen', 409);
          return { ...o, stage: 'demonstrated', close: null, queue: { ...o.queue, status: 'ready', followUpAt: null }, history: [...o.history, { stage: 'demonstrated', at: iso(), reason: 'reopened' }] };
        }
        case 'recover': this.stage(o, ['closed']); ensure(o.close.status === 'won', 'STAGE_CONFLICT', 'Recovery requires a won deal', 409); return beginRecovery(o);
        case 'measure': {
          this.stage(o, ['recovering']);
          ensure(!o.recovery.actions.some(action => ['executing','uncertain'].includes(action.status)), 'EXECUTION_UNRESOLVED', 'Reconcile outstanding executions before measuring', 409);
          const record = businessRecord(body.record);
          ensure(record.id === o.business.id, 'VALIDATION', 'Measurement business must match the opportunity');
          const enriched = this.analysis.analyze(record).business;
          const current = new Map(enriched.signals.map(s => [s.key, s]));
          for (const prior of o.business.signals) {
            const signal = current.get(prior.key);
            ensure(signal && Date.parse(signal.observedAt) >= Date.parse(prior.observedAt), 'INCOMPLETE_EVIDENCE', 'Measurement requires comparable, non-older evidence for every baseline signal');
          }
          ensure((enriched.averageTicket ?? current.get('average_ticket')?.value) === (o.business.averageTicket ?? o.business.signals.find(s => s.key === 'average_ticket')?.value), 'INCOMPLETE_EVIDENCE', 'Measurement must use the baseline average ticket');
          const metrics = Object.fromEntries(enriched.signals.map(s => [s.key, s.value]));
          const measured = measureOpportunity(o, { ...record, metrics });
          measured.measurement.evidence = enriched.signals;
          measured.measurement.executionMode = 'sandbox';
          measured.measurement.verifiedRecoveredRevenue = null;
          return measured;
        }
        default: ensure(false, 'NOT_FOUND', 'Operation not found', 404);
      }
    });
  }
  async queue(actor, id, body, key) {
    fields(body, ['version','operation','followUpAt'], ['version','operation']);
    choice(body.operation, ['claim','release','snooze','ready'], 'queue operation');
    return this.mutate(actor, id, `queue:${body.operation}`, body, key, ['operator','admin'], o => {
      ensure(o.queue.status !== 'done', 'QUEUE_CONFLICT', 'Closed queue item cannot be claimed', 409);
      const owned = o.queue.owner && o.queue.owner !== actor.actorId && Date.parse(o.queue.leaseUntil) > Date.now();
      ensure(!owned || actor.role === 'admin', 'QUEUE_CONFLICT', 'Prospect is leased by another operator', 409);
      if (body.operation === 'claim') {
        ensure(!o.queue.followUpAt || Date.parse(o.queue.followUpAt) <= Date.now(), 'QUEUE_CONFLICT', 'Prospect follow-up is not due', 409);
        o.queue = { ...o.queue, status: 'claimed', owner: actor.actorId, leaseUntil: new Date(Date.now() + 30 * 60_000).toISOString() };
      } else {
        const followUpAt = body.operation === 'snooze' ? timestamp(body.followUpAt, 'followUpAt') : null;
        if (followUpAt) ensure(Date.parse(followUpAt) > Date.now(), 'VALIDATION', 'Follow-up must be in the future');
        o.queue = { status: followUpAt ? 'snoozed' : 'ready', owner: null, leaseUntil: null, followUpAt };
      }
      return o;
    });
  }
  async draftOutreach(actor, id, body, key) {
    fields(body, ['version','channel','recipient','subject','body'], ['version','channel','recipient','subject','body']);
    choice(body.channel, ['email'], 'channel'); string(body.recipient, 'recipient', 320);
    ensure(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.recipient), 'VALIDATION', 'Invalid recipient');
    string(body.subject, 'subject', 200); string(body.body, 'body', 10000);
    return this.mutate(actor, id, 'outreach:draft', body, key, ['operator','admin'], o => {
      ensure(!['lost','deferred'].includes(o.close?.status), 'STAGE_CONFLICT', 'Reopen a deferred deal before outreach', 409);
      ensure(o.outreach.length < 100, 'CAPACITY', 'Outreach draft limit reached', 409);
      o.outreach.push({ id: randomUUID(), channel: body.channel, recipient: body.recipient, subject: body.subject, body: body.body, status: 'draft', approvalRequired: true, createdBy: actor.actorId, createdAt: iso() });
      return o;
    });
  }
  async approval(actor, id, kind, target, body, key) {
    choice(kind, ['recovery','outreach'], 'action kind');
    fields(body, ['version','decision','expiresAt','reason'], ['version','decision','reason']);
    choice(body.decision, ['approve','revoke'], 'decision'); string(body.reason, 'reason', 1000);
    return this.mutate(actor, id, `${kind}:${target}:${body.decision}`, body, key, ['approver','admin'], o => {
      if (kind === 'recovery') this.stage(o, ['recovering']);
      const action = actionsFor(o, kind).find(item => actionId(item) === target);
      ensure(action, 'NOT_FOUND', 'Action not found', 404);
      ensure(!['executing','uncertain','simulated'].includes(action.status), 'EXECUTION_CONFLICT', 'Action is already executing, unresolved, or completed', 409);
      if (body.decision === 'approve') {
        const expiresAt = timestamp(body.expiresAt, 'expiresAt');
        ensure(Date.parse(expiresAt) > Date.now() && Date.parse(expiresAt) <= Date.now() + 7 * 86400000, 'VALIDATION', 'Approval must expire within seven days');
        action.approval = { id: randomUUID(), actorId: actor.actorId, at: iso(), expiresAt, reason: body.reason, payloadHash: hash(payloadFor(action, kind)), connectorId: this.connectors.execution.id, mode: 'sandbox' };
        action.approved = true; action.status = 'approved';
      } else {
        action.approval = null; action.approved = false; action.status = kind === 'recovery' ? 'pending_approval' : 'draft';
      }
      action.approvalHistory = [...(action.approvalHistory || []), { decision: body.decision, actorId: actor.actorId, at: iso(), reason: body.reason, approval: action.approval }];
      if (o.recovery) o.recovery.approvedActionCount = o.recovery.actions.filter(a => a.approved).length;
      return o;
    });
  }
  async execute(actor, id, kind, target, body, key) {
    choice(kind, ['recovery','outreach'], 'action kind'); fields(body, ['version'], ['version']);
    let dispatch = false;
    // Write the execution intent BEFORE invoking the adapter. Replayed requests never invoke it again.
    const reserved = await this.mutate(actor, id, `${kind}:${target}:execute`, body, key, ['operator','admin'], o => {
      if (kind === 'recovery') this.stage(o, ['recovering']);
      ensure(!['lost','deferred'].includes(o.close?.status), 'STAGE_CONFLICT', 'Cannot execute for this close outcome', 409);
      const action = actionsFor(o, kind).find(item => actionId(item) === target);
      ensure(action, 'NOT_FOUND', 'Action not found', 404);
      ensure(action.status === 'approved' && action.approved && action.approval, 'APPROVAL_REQUIRED', 'Explicit action approval required', 409);
      const approval = action.approval;
      ensure(Date.parse(approval.expiresAt) > Date.now() && approval.payloadHash === hash(payloadFor(action, kind)) && approval.connectorId === this.connectors.execution.id && approval.mode === 'sandbox', 'APPROVAL_INVALID', 'Approval expired or no longer matches the action', 409);
      action.status = 'executing'; action.execution = { id: randomUUID(), at: iso(), approvalId: approval.id, actorId: actor.actorId };
      dispatch = true;
      return o;
    }, kind === 'recovery' ? 'recoveryAutomation' : 'diagnostics');
    if (!dispatch) return this.read(actor, id);
    const action = actionsFor(reserved, kind).find(item => actionId(item) === target);
    const controller = new AbortController();
    let timer, receipt, failed = false;
    try {
      receipt = await Promise.race([
        this.connectors.execution.execute({ executionId: action.execution.id, kind, payload: structuredClone(payloadFor(action, kind)), signal: controller.signal }),
        new Promise((_, reject) => { timer = setTimeout(() => { controller.abort(); reject(new Error('timeout')); }, this.executionTimeoutMs); }),
      ]);
      ensure(receipt?.executionId === action.execution.id && receipt.mode === 'sandbox' && receipt.status === 'simulated' && receipt.externalMutation === false, 'INVALID_RECEIPT', 'Invalid execution receipt', 502);
      string(receipt.reference, 'reference', 300);
      receipt = { executionId: receipt.executionId, provider: this.connectors.execution.id, mode: 'sandbox', status: 'simulated', externalMutation: false, reference: receipt.reference };
    } catch { failed = true; }
    finally { clearTimeout(timer); }
    const completed = await this.store.transaction(state => {
      const o = this.get(state, actor, id);
      const current = actionsFor(o, kind).find(item => actionId(item) === target);
      ensure(current.execution.id === action.execution.id && current.status === 'executing', 'EXECUTION_CONFLICT', 'Execution has changed; reconcile its result', 409);
      current.status = failed ? 'uncertain' : 'simulated';
      current.execution.finishedAt = iso();
      current.execution.receipt = failed ? null : receipt;
      current.execution.error = failed ? { code: 'PROVIDER_UNCERTAIN', message: 'Provider outcome unknown; explicit reconciliation required. Do not retry.' } : null;
      o.version++; o.updatedAt = iso();
      this.audit(state, actor, `${kind}:${target}:${current.status}`, id, { executionId: current.execution.id, version: o.version });
      return o;
    });
    return this.present(actor, completed);
  }
  async reconcile(actor, id, kind, target, body, key) {
    choice(kind, ['recovery','outreach'], 'action kind');
    fields(body, ['version','outcome','evidence'], ['version','outcome','evidence']);
    choice(body.outcome, ['not_executed','simulated'], 'outcome'); string(body.evidence, 'evidence', 2000);
    return this.mutate(actor, id, `${kind}:${target}:reconcile`, body, key, ['approver','admin'], o => {
      const action = actionsFor(o, kind).find(item => actionId(item) === target);
      ensure(action && action.status === 'uncertain', 'EXECUTION_CONFLICT', 'Only uncertain actions can be reconciled', 409);
      action.executions = [...(action.executions || []), { ...action.execution, reconciledBy: actor.actorId, reconciledAt: iso(), outcome: body.outcome, evidence: body.evidence }];
      action.approved = false; action.approval = null;
      action.status = body.outcome === 'simulated' ? 'simulated' : kind === 'recovery' ? 'pending_approval' : 'draft';
      if (o.recovery) o.recovery.approvedActionCount = o.recovery.actions.filter(a => a.approved).length;
      return o;
    });
  }
  async outreachOutcome(actor, id, target, body, key) {
    fields(body, ['version','outcome','notes','followUpAt'], ['version','outcome','notes']);
    choice(body.outcome, ['replied','meeting_booked','declined','no_response'], 'outreach outcome');
    string(body.notes, 'notes', 2000);
    const followUpAt = body.followUpAt === undefined ? null : timestamp(body.followUpAt, 'followUpAt');
    if (followUpAt) ensure(Date.parse(followUpAt) > Date.now(), 'VALIDATION', 'Follow-up must be in the future');
    return this.mutate(actor, id, `outreach:${target}:outcome`, body, key, ['operator','admin'], o => {
      const action = o.outreach.find(item => item.id === target);
      ensure(action, 'NOT_FOUND', 'Outreach draft not found', 404);
      ensure(action.status === 'simulated', 'STAGE_CONFLICT', 'Record sandbox outcomes only after simulated execution', 409);
      action.outcomes = [...(action.outcomes || []), { outcome: body.outcome, notes: body.notes, followUpAt, actorId: actor.actorId, at: iso(), mode: 'sandbox' }];
      if (followUpAt && o.queue.status !== 'done') o.queue = { status: 'snoozed', followUpAt, owner: null, leaseUntil: null };
      return o;
    });
  }
  /** Call once on startup, before accepting traffic. Never auto-retry interrupted actions. */
  async recoverInterruptedExecutions() {
    const snapshot = await this.store.read();
    if (!Object.values(snapshot.opportunities).some(o => [...(o.recovery?.actions || []), ...o.outreach].some(a => a.status === 'executing'))) return;
    await this.store.transaction(state => {
      for (const o of Object.values(state.opportunities)) {
        let changed = false;
        for (const action of [...(o.recovery?.actions || []), ...o.outreach]) if (action.status === 'executing') {
          action.status = 'uncertain'; action.execution.error = { code: 'INTERRUPTED', message: 'Interrupted execution requires reconciliation' }; changed = true;
        }
        if (changed) { o.version++; o.updatedAt = iso(); this.audit(state, { actorId: 'system', tenantId: o.tenantId }, 'execution:interrupted', o.id, { version: o.version }); }
      }
    });
  }
}
