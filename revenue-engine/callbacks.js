import { createHash, randomUUID } from 'node:crypto';
import { ensure } from './errors.js';
import { choice, fields, number, string, timestamp } from './validation.js';
import { normalizeVapiEvent, vapiBindings } from './vapi.js';
import { suppressionKey } from './missed-calls.js';

const now = () => new Date().toISOString();
const digest = value => createHash('sha256').update(JSON.stringify(value)).digest('hex');
const actionHash = task => digest([task.id, task.tenantId, task.opportunityId, task.call.callId, task.call.phone, task.dueAt]);
export class CallbackService {
  constructor(app, bindings = []) { this.app = app; this.bindings = vapiBindings(bindings); }
  task(state, actor, id) {
    const value = state.callbacks?.[id];
    ensure(value && value.tenantId === actor.tenantId, 'NOT_FOUND', 'Callback not found', 404); return value;
  }
  async ingest(actor, input) {
    this.app.authorize(actor, ['intake','operator','admin']);
    const event = normalizeVapiEvent(input);
    if (event.ignored) return event;
    const binding = this.bindings.find(b => b.tenantId === actor.tenantId && b.assistantId === event.assistantId && b.phoneNumberId === event.phoneNumberId);
    ensure(binding, 'VAPI_UNBOUND', 'Assistant and phone number have no binding for this tenant', 403);
    // Source deduplication spans credentials and retries; provider extras never enter durable storage.
    const id = digest([actor.tenantId, event.callId]);
    return this.app.store.transaction(state => {
      const opportunity = this.app.get(state, actor, binding.opportunityId);
      state.callbacks ||= {};
      if (state.callbacks[id]) {
        ensure(state.callbacks[id].opportunityId === binding.opportunityId, 'CALL_BINDING_CONFLICT', 'Call was already assigned to another opportunity', 409);
        return { duplicate: true, callbackId: id };
      }
      ensure(Object.keys(state.callbacks).length < 10000, 'CAPACITY', 'Callback queue capacity reached', 409);
      state.callbacks[id] = { id, tenantId: actor.tenantId, opportunityId: opportunity.id, version: 1, source: 'vapi',
        call: event, status: 'pending_approval', dueAt: now(), createdAt: now(), updatedAt: now(),
        approval: null, approvalHistory: [], attempts: [], history: [{ event: 'captured', at: now(), actorId: actor.actorId }],
        contactMode: 'manual', verifiedRecoveredRevenue: null };
      this.app.audit(state, actor, 'callback:captured', opportunity.id, { callbackId: id });
      return { duplicate: false, callbackId: id };
    });
  }
  async list(actor, query = {}) {
    this.app.authorize(actor, ['viewer','operator','approver','admin']);
    fields(query, ['status','offset','limit']);
    if (query.status) choice(query.status, ['pending_approval','approved','completed','cancelled'], 'callback status');
    const offset = query.offset === undefined ? 0 : Number(query.offset), limit = query.limit === undefined ? 50 : Number(query.limit);
    ensure(Number.isSafeInteger(offset) && offset >= 0 && Number.isSafeInteger(limit) && limit > 0 && limit <= 100, 'VALIDATION', 'Invalid pagination');
    const state = await this.app.store.read();
    const items = Object.values(state.callbacks || {}).filter(t => t.tenantId === actor.tenantId && (!query.status || t.status === query.status))
      .sort((a,b) => a.dueAt.localeCompare(b.dueAt) || a.id.localeCompare(b.id));
    return { items: items.slice(offset, offset + limit), total: items.length, offset, limit, nextOffset: offset + limit < items.length ? offset + limit : null };
  }
  async read(actor, id) {
    this.app.authorize(actor, ['viewer','operator','approver','admin']);
    return this.task(await this.app.store.read(), actor, id);
  }
  async change(actor, id, operation, body, key) {
    const extras = { approve: ['reason','expiresAt'], cancel: ['reason'], defer: ['reason','dueAt'], outcome: ['outcome','notes','dueAt'] };
    ensure(Object.hasOwn(extras, operation), 'NOT_FOUND', 'Callback operation not found', 404);
    fields(body, ['version', ...extras[operation]], ['version']);
    number(body.version, 'version', 1, Number.MAX_SAFE_INTEGER);
    ensure(Number.isSafeInteger(body.version), 'VALIDATION', 'Version must be an integer');
    return this.app.command(actor, `callback:${id}:${operation}`, body, key, operation === 'approve' ? ['approver','admin'] : ['operator','admin'], state => {
      const task = this.task(state, actor, id);
      ensure(task.version === body.version, 'VERSION_CONFLICT', 'Reload callback before changing it', 409);
      ensure(!['completed','cancelled'].includes(task.status), 'CALLBACK_CLOSED', 'Callback is already closed', 409);
      if (operation === 'approve') {
        string(body.reason, 'reason', 1000);
        ensure(task.call.phone, 'CALLBACK_NO_NUMBER', 'No valid callback number was captured', 409);
        ensure(!state.callbackSuppressions?.[suppressionKey(actor.tenantId, task.call.phone)], 'CALLBACK_SUPPRESSED', 'Caller requested no further callbacks', 409);
        const expiry = timestamp(body.expiresAt, 'expiresAt');
        ensure(Date.parse(expiry) > Math.max(Date.now(), Date.parse(task.dueAt)) && Date.parse(expiry) <= Date.now() + 7 * 86400000, 'VALIDATION', 'Approval must cover the due time and expire within seven days');
        task.approval = { id: randomUUID(), actorId: actor.actorId, at: now(), expiresAt: expiry, reason: body.reason, payloadHash: actionHash(task) };
        task.approvalHistory.push(task.approval); task.status = 'approved';
      } else if (operation === 'cancel') {
        string(body.reason, 'reason', 1000); task.status = 'cancelled'; task.approval = null;
      } else if (operation === 'defer') {
        string(body.reason, 'reason', 1000); const due = timestamp(body.dueAt, 'dueAt');
        ensure(Date.parse(due) > Date.now(), 'VALIDATION', 'Due time must be in the future');
        task.dueAt = due; task.status = 'pending_approval'; task.approval = null;
      } else {
        choice(body.outcome, ['reached','booked','no_answer','do_not_call'], 'callback outcome'); string(body.notes, 'notes', 2000);
        ensure(task.status === 'approved' && task.approval && task.approval.payloadHash === actionHash(task) && Date.parse(task.approval.expiresAt) > Date.now(), 'APPROVAL_REQUIRED', 'A current approval for this callback is required', 409);
        ensure(Date.parse(task.dueAt) <= Date.now(), 'CALLBACK_NOT_DUE', 'Callback is not due yet', 409);
        ensure(!state.callbackSuppressions?.[suppressionKey(actor.tenantId, task.call.phone)], 'CALLBACK_SUPPRESSED', 'Caller requested no further callbacks', 409);
        task.attempts.push({ outcome: body.outcome, notes: body.notes, actorId: actor.actorId, at: now(), approvalId: task.approval.id, reportedByHuman: true });
        task.approval = null;
        task.status = body.outcome === 'no_answer' ? 'pending_approval' : 'completed';
        if (body.outcome === 'no_answer') {
          const due = timestamp(body.dueAt, 'dueAt'); ensure(Date.parse(due) > Date.now(), 'VALIDATION', 'Schedule the next review in the future'); task.dueAt = due;
        }
        if (body.outcome === 'do_not_call') {
          state.callbackSuppressions ||= {};
          state.callbackSuppressions[suppressionKey(actor.tenantId, task.call.phone)] = { tenantId: actor.tenantId, at: now(), callbackId: id };
          for (const other of Object.values(state.callbacks)) if (other.id !== id && other.tenantId === actor.tenantId && other.call.phone === task.call.phone && !['completed','cancelled'].includes(other.status)) {
            other.status = 'cancelled'; other.approval = null; other.version++; other.updatedAt = now();
            other.history.push({ event: 'do_not_call', at: now(), actorId: actor.actorId });
            this.app.audit(state, actor, 'callback:suppressed', other.opportunityId, { callbackId: other.id });
          }
        }
      }
      task.version++; task.updatedAt = now(); task.history.push({ event: operation, at: now(), actorId: actor.actorId, ...(body.reason ? { reason: body.reason } : {}) });
      this.app.audit(state, actor, `callback:${operation}`, task.opportunityId, { callbackId: id, version: task.version });
      return task;
    });
  }
  async summary(actor) {
    this.app.authorize(actor, ['viewer','operator','approver','admin']);
    const state = await this.app.store.read();
    const result = { total: 0, pendingApproval: 0, approved: 0, completed: 0, cancelled: 0, needsNumber: 0, due: 0, reportedBookings: 0, verifiedRecoveredRevenue: null, contactMode: 'manual' };
    for (const task of Object.values(state.callbacks || {})) if (task.tenantId === actor.tenantId) {
      result.total++; result[task.status === 'pending_approval' ? 'pendingApproval' : task.status]++;
      if (!task.call.phone) result.needsNumber++;
      if (!['completed','cancelled'].includes(task.status) && Date.parse(task.dueAt) <= Date.now()) result.due++;
      result.reportedBookings += task.attempts.filter(a => a.outcome === 'booked').length;
    }
    return result;
  }
}
