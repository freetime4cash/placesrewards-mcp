import { ensure } from './errors.js';
import { fields, identifier, object, string, timestamp } from './validation.js';

export function vapiBindings(value = []) {
  ensure(Array.isArray(value) && value.length <= 100, 'CONFIG', 'Vapi bindings must be an array of at most 100 entries', 503);
  const seen = new Set();
  return value.map(item => {
    fields(item, ['tenantId','opportunityId','assistantId','phoneNumberId'], ['tenantId','opportunityId','assistantId','phoneNumberId']);
    for (const [key, val] of Object.entries(item)) identifier(val, key);
    const key = JSON.stringify([item.tenantId, item.assistantId, item.phoneNumberId]);
    ensure(!seen.has(key), 'CONFIG', 'Duplicate Vapi binding', 503); seen.add(key);
    return { ...item };
  });
}

/** Accept a provider envelope, retain only callback-review fields, never infer SMS permission. */
export function normalizeVapiEvent(input) {
  object(input); object(input.message, 'message');
  const { message } = input;
  string(message.type, 'message type', 80);
  if (message.type !== 'end-of-call-report') return { ignored: true, reason: 'unsupported-event' };
  object(message.call, 'call'); const call = message.call;
  identifier(call.id, 'call id'); identifier(call.assistantId, 'assistant id'); identifier(call.phoneNumberId, 'phone number id');
  if (call.type !== 'inboundPhoneCall') return { ignored: true, reason: 'not-inbound-phone-call' };
  const endedAt = timestamp(call.endedAt, 'call endedAt');
  ensure(Date.parse(endedAt) <= Date.now() + 60000, 'VALIDATION', 'Call end cannot be in the future');
  const candidate = call.customer?.number;
  const phone = typeof candidate === 'string' && /^\+[1-9]\d{7,14}$/.test(candidate) ? candidate : null;
  const analysis = message.analysis || call.analysis || {};
  object(analysis, 'analysis');
  const summary = typeof analysis.summary === 'string' ? analysis.summary.slice(0, 4000) : 'Review this inbound call before deciding whether a callback is appropriate.';
  const name = typeof call.customer?.name === 'string' ? call.customer.name.slice(0, 200) : null;
  return { callId: call.id, assistantId: call.assistantId, phoneNumberId: call.phoneNumberId, endedAt, phone, name, summary,
    endedReason: typeof message.endedReason === 'string' ? message.endedReason.slice(0, 200) : typeof call.endedReason === 'string' ? call.endedReason.slice(0, 200) : 'unknown',
    callbackRequested: analysis.structuredData?.callbackRequested === true,
    evidence: `vapi:call:${call.id}` };
}

export function callToEvent(call) {
  object(call, 'call');
  if (call.status !== 'ended') return null;
  return { message: { type: 'end-of-call-report', call, analysis: call.analysis, endedReason: call.endedReason } };
}
