import { createHash } from 'node:crypto';
import { ensure } from './errors.js';
import { fields, identifier, string, timestamp } from './validation.js';

export const suppressionKey = (tenantId, phone) => createHash('sha256').update(JSON.stringify([tenantId, phone])).digest('hex');
export function phone(value, label) {
  string(value, label, 16);
  ensure(/^\+[1-9]\d{7,14}$/.test(value), 'VALIDATION', `${label} must be an international E.164 phone number`);
  return value;
}
export function followUp(body, business, now = Date.now()) {
  fields(body, ['version','event','smsPermission','bookingUrl','sendAfter'], ['version','event','smsPermission']);
  fields(body.event, ['id','caller','businessNumber','occurredAt','evidence'], ['id','caller','businessNumber','occurredAt','evidence']);
  identifier(body.event.id, 'call event id');
  phone(body.event.caller, 'caller'); phone(body.event.businessNumber, 'businessNumber');
  ensure(body.event.caller !== body.event.businessNumber, 'VALIDATION', 'Caller and business numbers must differ');
  const occurredAt = timestamp(body.event.occurredAt, 'occurredAt');
  ensure(Date.parse(occurredAt) <= now && Date.parse(occurredAt) >= now - 86400000, 'VALIDATION', 'Missed call must be within the last 24 hours');
  string(body.event.evidence, 'call evidence', 2000);
  fields(body.smsPermission, ['allowed','evidence'], ['allowed','evidence']);
  ensure(body.smsPermission.allowed === true, 'SMS_PERMISSION_REQUIRED', 'Record permission to text this caller before drafting');
  string(body.smsPermission.evidence, 'SMS permission evidence', 2000);
  let bookingUrl;
  if (body.bookingUrl !== undefined) {
    string(body.bookingUrl, 'bookingUrl', 500);
    try { bookingUrl = new URL(body.bookingUrl); } catch { ensure(false, 'VALIDATION', 'Invalid booking URL'); }
    ensure(bookingUrl.protocol === 'https:' && !bookingUrl.username && !bookingUrl.password, 'VALIDATION', 'Booking URL must use HTTPS without credentials');
    bookingUrl = bookingUrl.href;
  }
  const sendAfter = body.sendAfter === undefined ? new Date(now).toISOString() : timestamp(body.sendAfter, 'sendAfter');
  const expiresAt = new Date(Date.parse(occurredAt) + 86400000).toISOString();
  ensure(Date.parse(sendAfter) < Date.parse(expiresAt), 'VALIDATION', 'Scheduled follow-up must be within 24 hours of the missed call');
  const message = `Hi, this is ${business.name}. Sorry we missed your call. Reply here or call ${body.event.businessNumber} and we will help.${bookingUrl ? ` Book a time: ${bookingUrl}` : ''} Reply STOP to opt out.`;
  ensure(message.length <= 1000, 'VALIDATION', 'Follow-up message is too long');
  return { eventId: body.event.id, caller: body.event.caller, businessNumber: body.event.businessNumber, occurredAt, evidence: body.event.evidence,
    smsPermission: structuredClone(body.smsPermission), sendAfter, expiresAt, bookingUrl: bookingUrl || null, message,
    disposition: 'pending', responses: [] };
}
export function requireFollowUpEligible(action, state, tenantId, { dispatch = false } = {}) {
  if (!action.missedCall) return;
  const call = action.missedCall;
  ensure(call.disposition === 'pending', 'FOLLOWUP_STOPPED', 'Caller has replied, opted out, or completed follow-up', 409);
  ensure(!state.smsSuppressions?.[suppressionKey(tenantId, call.caller)], 'SMS_SUPPRESSED', 'Caller is opted out for this tenant', 409);
  ensure(call.smsPermission.allowed === true, 'SMS_PERMISSION_REQUIRED', 'SMS permission is required', 409);
  ensure(Date.parse(call.expiresAt) > Date.now(), 'FOLLOWUP_EXPIRED', 'Missed-call follow-up has expired', 409);
  if (dispatch) ensure(Date.parse(call.sendAfter) <= Date.now(), 'FOLLOWUP_NOT_DUE', 'Follow-up is not due yet', 409);
}
export function missedCallPayload(call) {
  return { channel: 'sms', to: call.caller, from: call.businessNumber, body: call.message, eventId: call.eventId,
    occurredAt: call.occurredAt, smsPermission: call.smsPermission, sendAfter: call.sendAfter, expiresAt: call.expiresAt };
}
