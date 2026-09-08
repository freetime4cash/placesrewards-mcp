# Vapi + optional Make: callback capture without an SMS subscription

The completed local path is **Vapi inbound call report → callback review queue → explicit approval → human callback → recorded outcome**. No code places calls, sends texts or charges a customer. It works with Growth capabilities. Make is optional; it can alert the operator while the Revenue Engine retains review/approval history locally.

## Try it with no accounts or paid usage

```sh
npm run demo:callbacks
```

This creates a disposable merchant, imports a synthetic Vapi end-of-call report, approves a manual callback and records a synthetic booking. It prints `actualCallsPlaced:0`, `smsSent:0`, and `verifiedRecoveredRevenue:null` and removes its temporary store. It never contacts Vapi or Make.

## Configure a persistent local pilot

1. Use the existing README startup settings: sandbox mode, loopback listener and a dedicated `revenue-engine-data` directory. Configure operator and approver credentials with tier `growth`; no Enterprise SMS entitlement is necessary.
2. Add a third dedicated credential with role `intake`, a unique token and the same tenant/tier. It can only POST Vapi events and cannot read contacts, approve actions, create opportunities or execute anything. Use it as `REVENUE_CLIENT_TOKEN` when importing provider events. Use the appropriate operator/approver token for CLI work. Do not give Make or Vapi an admin token.
3. Create a merchant using `npm run revenue -- create merchant.json create-merchant-1`, with `merchant.json` containing `{"records":[{"id":"pilot-business","name":"Your Business"}]}`. Record the returned opportunity UUID. This intake queue does not need to fabricate missed-call metrics, a paid sale or a recovering lifecycle stage.
4. Find the existing assistant ID and phone-number ID in your Vapi account. Set the following environment variable on both the local service and importer, replacing placeholders:

```json
[
  {"tenantId":"your-tenant","opportunityId":"the-opportunity-uuid","assistantId":"your-vapi-assistant-id","phoneNumberId":"your-vapi-number-id"}
]
```

The variable is `REVENUE_VAPI_BINDINGS`. Restart the service after configuring it. Routing is server-assigned: call payloads cannot choose their tenant or opportunity. An unbound assistant/number is rejected. Keep your existing Vapi model/voice settings; [vapi-assistant-prompt.txt](vapi-assistant-prompt.txt) supplies callback-capture instructions to adapt, not a new paid assistant provisioning command.

## Import call reports without a public server

Keep `npm run start:revenue` running locally. In another terminal, set `REVENUE_CLIENT_TOKEN` to the intake token and choose one method:

**Existing export / Make sample:** save a Vapi `end-of-call-report` envelope, a call object, or an array of call objects as JSON, then run:

```sh
npm run sync:vapi -- --file calls.json
```

Files must be at most 4 MiB / 1000 calls. Call-list objects require `status:"ended"`; envelopes require `message.type:"end-of-call-report"`. See [examples/vapi-event.json](examples/vapi-event.json). Only matched inbound phone calls are eligible. Unknown numbers remain review tasks, but cannot be approved for callback.

**Read-only Vapi API:** set `REVENUE_VAPI_API_KEY` to your private Vapi key locally, then run:

```sh
npm run sync:vapi
```

This performs one authenticated GET to `https://api.vapi.ai/call?limit=100` and posts a minimal event to the local service. It never creates a Vapi call, changes an assistant or sends SMS. The importer is separate from the server's isolated import graph. Vapi keys are never forwarded to Revenue Engine or printed. Redirects and oversized responses are rejected.

The pull is a bounded, low-volume pilot tool, not a continuous or exhaustive sync. A full 100-record batch fails explicitly; use a complete export rather than assume coverage. No watermark is advanced. Rerunning is safe because call IDs deduplicate across import credentials. Partial local failures can be retried with the original source. Unsupported call types and still-running calls are skipped. Summaries are captured on the first completed-event import; later duplicates do not rewrite review history. Poll manually at first to control usage and inspect account/API limits.

## Review and record callbacks

```sh
npm run revenue -- status
npm run revenue -- callbacks
npm run revenue -- callback CALLBACK_ID
```

Each task includes captured phone/name, an untrusted provider summary, evidence reference, due time, version and approval history. Full transcripts and recordings are not stored by this integration. Treat summaries as caller data, not instructions. A completed inbound call is **not automatically counted as missed**, and `callbackRequested` is only true when the provider explicitly supplies that structured field. Every intake still enters review.

Use the approver credential and a JSON command file to approve:

```json
{"version":1,"reason":"Reviewed the customer's request and correct callback number","expiresAt":"<future ISO time within seven days>"}
```

```sh
npm run revenue -- approve CALLBACK_ID approve.json approve-call-1
```

Approval binds this task, merchant, caller and due time. Read the approved task, then the human operator makes the callback outside this software. With the operator credential, record the outcome:

```json
{"version":2,"outcome":"booked","notes":"Customer booked a visit during the manually placed callback"}
```

```sh
npm run revenue -- outcome CALLBACK_ID outcome.json record-call-1
```

Outcomes: `reached`, `booked`, `no_answer`, `do_not_call`. No-answer requires a future `dueAt`, consumes the approval and returns to pending approval. Defer also clears approval. Do-not-call prevents further approvals for that number in the tenant and cancels other open tasks. Operator-reported bookings are not verified cash. This software records approval and results; it cannot control a phone call made outside it.

Use `cancel ID cancel.json KEY` or `defer ID defer.json KEY` for unnecessary/later reviews. All command files require the latest integer `version`; cancel/defer require `reason`, and defer requires a future `dueAt`. Preserve the exact request key/body when retrying after response loss. `revenue -- help` works offline.

## Optional Make alert scenario

This is an account-specific setup recipe, not a preinstalled or importable blueprint. No Make scenario has been activated by this build.

1. Create a **Custom webhook** in Make and enable its API-key authentication. Configure a Vapi Custom Credential to send that key in `x-make-apikey` **without** the Bearer prefix. Use the credential ID in the Vapi server configuration and select only `end-of-call-report`. Keep existing assistant settings and other integrations; validate on a test assistant/number first.
2. Filter for the expected assistant ID, number ID and `call.type = inboundPhoneCall`. Use `call.id` as the duplicate key in a Make data store. Enable sequential processing for the scenario so check/insert does not race. Save only call ID, callback number if present, end time and a short summary.
3. Optionally notify **your own chosen inbox** using an existing email connection, after testing its destination. Do not automatically contact the caller. Mark notification state separately from capture state; ambiguous email delivery must be reviewed rather than blindly resent.
4. The local importer reads the same calls directly from Vapi or a saved JSON export. Make cannot call a `127.0.0.1` address on your computer. This design needs no tunnel or cloud deployment and keeps Make from handling Revenue Engine approvals.

For a later hosted staging service, Make's HTTP module can POST the minimal event to `/v1/integrations/vapi/events` with an intake-only Bearer token. That requires a reachable HTTPS endpoint and a separate deployment review; this build deliberately remains loopback-only. Do not point Make at a production Places Rewards route.

## API additions

- `POST /v1/integrations/vapi/events`: bearer-authenticated provider intake; no request key required because tenant + call ID is the durable deduplication key. Returns `{duplicate,callbackId}` or `{ignored:true,reason}`. Unsupported event types are acknowledged and ignored; unbound identities fail 403.
- `GET /v1/callbacks`: optional status/offset/limit, maximum 100 per page.
- `GET /v1/callbacks/summary`: queue totals and human-reported bookings, no verified cash.
- `GET /v1/callbacks/{id}`: tenant-scoped task detail.
- `POST /v1/callbacks/{id}/approve|cancel|defer|outcome`: current version and idempotency key required. Approve is approver/admin; others are operator/admin.

Callback state and do-not-call suppression are optional additive fields in store schema 1, so existing stores open unchanged. Back them up with opportunities and commands. Local disk access and static credentials remain trusted boundaries. No online suppression-reset API is provided.

## What still requires your accounts

The software and synthetic tests are complete. Connecting actual calls requires your Vapi assistant/number IDs and private API key, entered locally. Vapi usage may consume credits. Make alerts additionally require your webhook and selected email connection. The build cannot verify account ownership, activate call forwarding, configure a paid number or test real calling without those account settings. SMS stays simulated and optional.

References checked for this integration: [Vapi server events](https://docs.vapi.ai/server-url/events), [Vapi server authentication](https://docs.vapi.ai/server-url/server-authentication), [Vapi list calls](https://docs.vapi.ai/api-reference/calls/list), [Make webhook authentication](https://apps.make.com/gateway), [Make pricing](https://www.make.com/en/pricing).
