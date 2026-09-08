# Missed-call follow-up

One missed call produces one reviewable SMS recovery action. The included transport simulates delivery only. No live provider is configured, no webhook is publicly exposed, and no message is sent. The workflow is ready for a provider-specific staging adapter once the provider and test account are selected.

## Flow

1. An Enterprise operator progresses a diagnosed missed-call opportunity to a won close and `/recover`.
2. Record an authorized missed-call event using the endpoint below. The event must be less than 24 hours old, have distinct E.164 caller/business numbers and include source evidence plus documented permission to text. A missed call alone does not populate SMS permission automatically.
3. The application creates a pending action with exact text, recipient, sender, optional booking link, schedule and expiry. Example: `Hi, this is Sandbox Dental. Sorry we missed your call. Reply here or call +12025550124 and we will help. Book a time: https://example.test/book Reply STOP to opt out.`
4. An approver reviews the complete action and approves through the existing recovery approval endpoint. The approval hash binds phone numbers, message, permission, call ID, schedule and expiry.
5. An operator explicitly executes after `sendAfter` and before the 24-hour expiry. The existing durable intent, idempotency, timeout and reconciliation protections apply. Scheduling is a due-time gate, not a background sender. There is no automatic repeat sequence.
6. Record a reply, callback request, booking or opt-out. Replies stop pending follow-ups in that opportunity. STOP (also STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT) suppresses this number throughout the tenant and invalidates pending approvals. Suppressions survive restart. Ordinary replies cannot clear an opt-out. An already-dispatched action cannot be recalled.

## Create an action

`POST /v1/opportunities/{id}/missed-calls`, with bearer authentication and a unique `Idempotency-Key`:

```json
{
  "version": 7,
  "event": {
    "id": "provider-call-unique-id",
    "caller": "+12025550123",
    "businessNumber": "+12025550124",
    "occurredAt": "<recent ISO timestamp>",
    "evidence": "authorized-call-export:unique-id"
  },
  "smsPermission": {"allowed": true, "evidence": "permission-record:unique-id"},
  "bookingUrl": "https://example.test/book",
  "sendAfter": "<ISO timestamp before expiry>"
}
```

`bookingUrl` and `sendAfter` are optional. Booking links must use HTTPS without embedded credentials. The default schedule is now; explicit approval is still required. Duplicate call IDs are rejected across the tenant, independent of the request key. Replay the exact original key/body after a lost response. Use provider-prefixed IDs when importing multiple sources.

Read the resulting action from `recovery.actions`; its unique `leakId` starts with `call:`. Review `missedCall.message`, then use:

- `POST /v1/opportunities/{id}/recovery/{encodedLeakId}/approval` with `{version,decision:"approve",reason,expiresAt}`.
- `POST /v1/opportunities/{id}/recovery/{encodedLeakId}/execute` with `{version}`.

The per-call action has modeled recovery value zero to avoid duplicating the aggregate missed-call opportunity. Simulated receipt does not imply a real send or recovered revenue.

## Record a response

`POST /v1/opportunities/{id}/missed-calls/{encodedLeakId}/response`:

```json
{"version":11,"eventId":"provider-response-id","text":"Please call me back","outcome":"callback_requested"}
```

Outcome: `replied`, `callback_requested`, `booked`, `opted_out`. STOP text overrides the supplied outcome. The endpoint is an authenticated operator import, **not a provider webhook**. Provider identity, webhook signature checks, number ownership, real delivery callbacks and live send authorization must be added in the provider adapter before connecting a real account. Do not expose this endpoint as an unsigned public webhook.

Actions and reply history are included in opportunity/report contracts. Dashboard `missedCalls` counts total, pending approvals, scheduled, expired, simulated, replied, callback requested, booked, opted out and uncertain. Execution and response counts overlap: a simulated action may later be booked. Retain suppression history with backups; deleting it can remove opt-outs. No API clears suppressions in this version.

Additional errors: `SMS_PERMISSION_REQUIRED`, `SMS_SUPPRESSED`, `DUPLICATE_CALL`, `DUPLICATE_RESPONSE`, `FOLLOWUP_STOPPED`, `FOLLOWUP_EXPIRED`, `FOLLOWUP_NOT_DUE`, `NO_MISSED_CALL_PLAN`. Existing role/tier, version, approval and idempotency errors also apply.
