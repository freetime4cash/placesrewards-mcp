# HTTP API v1

Base URL: `http://127.0.0.1:4311/v1`. Except health, all routes require `Authorization: Bearer <token>`. Responses use `Cache-Control: no-store` and `X-Request-Id`. There is no CORS grant or cookie authentication.

Success: `{contractVersion:"1.0",requestId,ok:true,data}`. Error: `{contractVersion:"1.0",requestId,ok:false,error:{code,message}}`. Success returns HTTP 200, including create and simulated execution. `GET /health` is outside `/v1` and returns readiness, `service`, `mode` and `externalMutation` directly in the envelope.

## Request rules

POST requires `Content-Type: application/json`, no compression, and `Idempotency-Key` (1–160 characters, alphanumeric first, then alphanumeric or `_.:@-`). Bodies are capped at 256 KiB; unknown fields are rejected. Header/body timeouts are 10/15 seconds. At most 32 handlers run concurrently; provider timeout is five seconds.

The Vapi intake exception is `POST /v1/integrations/vapi/events`: provider envelopes can contain additional fields, but only selected callback data is retained. Tenant + call ID replaces the request key for source deduplication. See [VAPI-MAKE.md](VAPI-MAKE.md) for authenticated intake, bindings, callback routes, the local CLI, and the optional Make setup.

Keys are scoped to tenant + actor and retained. A key reused for a different route/body returns 409. Successful command replay returns the original snapshot; GET the opportunity for its latest version. Execution replay returns current state and never dispatches twice. On response loss, retry the exact request with the same key. Failed pre-commit commands are not recorded.

Every opportunity mutation requires a positive integer `version` from GET. Stale versions return 409 without changes. Execution increments twice (intent and receipt). Use the opportunity UUID in paths, not business ID; URL-encode action IDs.

## Routes

| Method/path (relative to `/v1`) | Body/query | Result |
| --- | --- | --- |
| GET `/providers` | None | Connector descriptions |
| POST `/opportunities` | `{records:[record]}` exactly one | New opportunity array |
| POST `/discover` | `{records:[record,...]}` 1–100 | Ranked prospect array |
| POST `/providers/{provider}/import` | `{records:[record,...]}` 1–100 | Atomic import and ranking |
| GET `/opportunities` or `/prospects` | Optional `stage`, `queue`, `offset`, `limit` | Opportunity page |
| GET `/opportunities/{id}` | None | Opportunity/version |
| GET `/opportunities/{id}/evidence` | None | Signals/quality |
| GET `/opportunities/{id}/report` | None | Report contract |
| GET `/dashboard` | None | Tenant dashboard contract |
| GET `/audit` | `offset`, `limit`; reviewer/admin | Tenant audit page |
| POST `/opportunities/{id}/diagnose` | `{version}` | Discovered → diagnosed |
| POST `/opportunities/{id}/quantify` | `{version}` | Diagnosed → quantified |
| POST `/opportunities/{id}/prescribe` | `{version}` | Quantified → prescribed |
| POST `/opportunities/{id}/demo` | `{version}` | Prescribed → demonstrated |
| POST `/opportunities/{id}/close` | `{version,status,agreedMonthlyFee?,recoverySharePercent?,notes?}` | Demonstrated → closed |
| POST `/opportunities/{id}/reopen` | `{version}` | Deferred → demonstrated |
| POST `/opportunities/{id}/recover` | `{version}` | Won → recovering; no approvals/execution |
| POST `/opportunities/{id}/measure` | `{version,record}` | Recovering → measured |
| POST `/opportunities/{id}/queue` | `{version,operation,followUpAt?}` | Claim/release/schedule |
| POST `/opportunities/{id}/outreach` | `{version,channel,recipient,subject,body}` | Immutable draft |
| POST `/opportunities/{id}/{kind}/{actionId}/approval` | `{version,decision,reason,expiresAt?}` | Approve/revoke |
| POST `/opportunities/{id}/{kind}/{actionId}/execute` | `{version}` | Intent and simulated receipt |
| POST `/opportunities/{id}/{kind}/{actionId}/reconcile` | `{version,outcome,evidence}` | Explicit reconciliation |
| POST `/opportunities/{id}/outreach/{actionId}/outcome` | `{version,outcome,notes,followUpAt?}` | Sandbox reply and follow-up |

`kind` is `recovery` or `outreach`; action ID is recovery `leakId` or outreach `id`. See README for roles/tiers.

Pages default to offset 0 / limit 50 (max 100). Opportunity pages contain `{items,total,offset,limit,nextOffset}`; audit omits `nextOffset`. Sort is score descending, UUID ascending. Paging reflects current state, not a cross-request snapshot. Stage/queue filters use the enums in `contracts.d.ts`.

One opportunity per business ID per tenant is supported. Invalid/duplicate batches fail atomically. Discovery retains low-priority/nurture prospects. This version does not replace baseline evidence or create repeated opportunities for the same business.

## Evidence record

```json
{
  "records": [{
    "id":"sandbox-dental", "name":"Sandbox Dental", "averageTicket":250,
    "calls":{"missedCallsMonthly":20,"callConversionRate":0.4,"observedAt":"2026-09-01T00:00:00Z","evidence":"authorized-export:calls-1"},
    "crm":{"uncontactedLeadsMonthly":10,"leadCloseRate":0.2,"dormantCustomers":100,"reactivationRate":0.08,"repeatPurchaseGapMonthly":3,"observedAt":"2026-09-01T00:00:00Z","evidence":"authorized-export:crm-1"}
  }]
}
```

Required: string `id`, `name`. Optional top-level: `industry`, `averageTicket`, `monthlyRevenue`, `source`, `observedAt`, `metrics`, `calls`, `crm`, `publicProfile`, `website`. Business ID syntax matches idempotency keys. Money is finite/nonnegative; ticket ≤1,000,000, monthly revenue ≤1,000,000,000; rates 0–1; count metrics ≤10,000,000; rating 0–5. Numeric strings are rejected.

`metrics` keys: `missed_calls_monthly`, `call_conversion_rate`, `uncontacted_leads_monthly`, `lead_close_rate`, `dormant_customers`, `reactivation_rate`, `repeat_purchase_gap_monthly`, `average_ticket`, `review_rating`, `review_count`, `response_time_hours`, `social_inactivity_days`, `website_load_seconds`, `website_mobile_score`, plus boolean `website_has_booking`, `website_has_contact_form`, `website_has_loyalty`, `website_has_referral`.

`calls` and `crm` fields appear above; CRM also accepts `averageTicket`. `publicProfile` fields: `reviewRating`, `reviewCount`, `websiteHasBooking`, `websiteHasContactForm`, `websiteHasLoyalty`, `websiteHasReferral`, `responseTimeHours`, `socialInactivityDays`. `website` fields: `hasBooking`, `hasContactForm`, `hasLoyalty`, `hasReferral`, `loadSeconds`, `mobileScore`.

Provider sections accept metadata: `observedAt`, `source`, `evidence`, `reviewUrl`, `websiteUrl`, `responseEvidence`, `socialUrl`, `url`. References are stored as data, never fetched. Render as escaped text in clients. Missing timestamps describe ingestion time; preserve source dates for meaningful comparison.

## Workflow rules

- Close status is explicitly `won`, `lost`, or `deferred`. Share is 0–100; fees are agreement metadata, never billing. Lost is terminal; deferred can reopen; only won can recover.
- Queue operations: `claim`, `release`, `snooze`, `ready`. Claims last 30 minutes. Other operators cannot take an active lease; admins may override. Expired leases can be claimed on demand. Snooze requires a future follow-up. No background worker changes queue labels; claim checks due time. `ready` clears snooze.
- Outreach is email-shaped, with a valid recipient, subject ≤200 and body ≤10,000. Drafts are immutable; create a new draft and revoke the old approval to revise. Outcomes after simulated execution: `replied`, `meeting_booked`, `declined`, `no_response`. Optional follow-up snoozes non-done prospects. No message is sent.
- Approval decision: `approve` or `revoke`; reason ≤1,000. Approve requires expiry in the next seven days. Server computes the payload hash and retains approval history. Callers cannot submit approvals through lifecycle fields. Running, uncertain and completed actions cannot be reapproved or revoked.
- Reconciliation: `not_executed` or `simulated`, with evidence ≤2,000. Only uncertain actions qualify. Confirmed non-execution clears approval and requires a fresh approval before retry. No real mutation is claimed.
- Measurement requires identical business and effective ticket, complete baseline signal coverage, non-older observations, and no unresolved recovery execution. It is a final modeled snapshot, not causation or cash verification.

## Errors and retries

| HTTP | Codes/examples | Handling |
| --- | --- | --- |
| 400 | `VALIDATION`, `INVALID_JSON`, `INCOMPLETE_EVIDENCE`, `INVALID_URL` | Correct input |
| 401 / 403 | `UNAUTHORIZED`, `FORBIDDEN`, `ENTITLEMENT` | Correct identity/permission |
| 404 | `NOT_FOUND` | Missing or wrong-tenant resource |
| 409 | `VERSION_CONFLICT`, `STAGE_CONFLICT`, `IDEMPOTENCY_CONFLICT`, `DUPLICATE_BUSINESS`, `QUEUE_CONFLICT`, `APPROVAL_REQUIRED`, `APPROVAL_INVALID`, `EXECUTION_CONFLICT`, `EXECUTION_UNRESOLVED` | Reload state, review approval or reconcile |
| 413 / 414 / 415 | `BODY_TOO_LARGE`, `URI_TOO_LONG`, `CONTENT_TYPE`, `CONTENT_ENCODING` | Correct request size/format |
| 429 | `BUSY` | Back off, keep same key |
| 500 / 503 | `INTERNAL`, `STORE_UNAVAILABLE`, startup config/store errors | Inspect health and request ID |
| 507 | `STORE_CAPACITY` | Stop intake and perform offline maintenance |

Provider failure returns a committed opportunity with action status `uncertain` and sanitized `execution.error`, normally HTTP 200. Inspect action status, not just HTTP status. Receipt-persistence failure returns an error while retaining intent; restart marks it uncertain. Never change idempotency keys to force execution after an unknown result.
