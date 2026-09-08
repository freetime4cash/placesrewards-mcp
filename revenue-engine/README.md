# Revenue Engine operational application

An independently launched application for **Discover → Diagnose → Quantify → Prescribe → Demonstrate → Close → Recover → Measure**.

Implemented: durable opportunities, ranked prospect queue, tenant-scoped HTTP APIs, provider evidence imports, outreach and closing workflows, action-specific approvals, simulated execution, reconciliation, and report/dashboard contracts. This is a **local sandbox integration**. It includes no production provider credentials, real outbound messaging, billing, or Places Rewards mutation adapter. A simulated receipt is never reported as actual delivery or recovered cash.

## Run

Node.js 20 or newer. No third-party packages or installation step required.

```sh
npm run test:revenue
npm run demo:revenue
```

The demo uses synthetic evidence in a disposable temporary directory, explicitly approves an action as a reviewer, and prints modeled dashboard totals. Tests use temporary stores and local HTTP sockets.

For a persistent API, configure the environment of a **separate** process:

| Variable | Required/default | Meaning |
| --- | --- | --- |
| `REVENUE_ENABLED` | `true` required | Explicit opt-in |
| `REVENUE_ENV` | `sandbox` or `test` required | Production is rejected |
| `REVENUE_AUTH` | Required JSON array | Server-assigned credentials/tenant/role/tier |
| `REVENUE_DATA_DIR` | `./revenue-engine-data` | Dedicated local directory; final component must be `revenue-engine-data` |
| `REVENUE_HOST` | `127.0.0.1` | Only loopback (`127.0.0.1` or `::1`) |
| `REVENUE_PORT` | `4311` | Integer 1024–65535 |

`NODE_ENV=production` is also rejected. Start with `npm run start:revenue`. Importing `revenue-engine/server.js` does not start a listener. All original production commands and entrypoints remain unchanged.

`REVENUE_AUTH` format (replace placeholders with independent random tokens of at least 32 characters):

```json
[
  {"token":"<operator-random-token>","tenantId":"sandbox-tenant","actorId":"operator-1","role":"operator","tier":"enterprise"},
  {"token":"<reviewer-random-token>","tenantId":"sandbox-tenant","actorId":"reviewer-1","role":"approver","tier":"enterprise"}
]
```

Generate each token with `node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"`. Never commit real tokens or reuse production credentials. Tokens are hashed in memory and compared in constant time. Rotate/revoke credentials by changing the process environment and restarting.

## Roles and capability tiers

| Role | Permissions |
| --- | --- |
| viewer | Tenant-scoped reads |
| operator | Import/create, lifecycle, queue, outreach drafts/outcomes, execute approved actions |
| approver | Reads, audit, approve/revoke, reconcile uncertain executions |
| admin | Both operator and approver permissions |

Separate operator/reviewer identities are recommended; admin intentionally supports both. Request bodies cannot assign tenant, role or tier. Opportunity tiers are fixed at creation. The application checks current caller capability and the opportunity's capability for operations that require it.

| Tier | Capabilities |
| --- | --- |
| disabled | No API access |
| growth | Manual diagnostics, planning, demo, close, outreach, modeled measurement |
| pro | Growth plus discovery/import/ranking and advanced report/dashboard |
| enterprise | Pro plus recovery execution and continuous monitoring entitlement |

Growth and Pro can enter recovery planning; only Enterprise can execute approved recovery actions. Billing and plan resolution remain external. No Stripe or Places Rewards entitlement lookup occurs.

## Evidence and providers

Five detectors cover missed calls, uncontacted leads, dormant customers, repeat purchases and reputation. Evidence quality considers age, coverage and traceability. Unknown observations remain unknown. Discovery and persisted diagnosis use the same enriched evidence and confidence adjustment.

Import connectors: `merchant-export`, `crm-export`, `call-export`, `public-profile-export`, `website-export`. They validate supplied authorized records and preserve provenance. They do not fetch arbitrary URLs, crawl providers or read external files. Preserve original observation timestamps; missing timestamps describe ingestion time, not verified source freshness.

`ConnectorRegistry` defines import and execution boundaries. The bundled executor returns `status:simulated`, `mode:sandbox`, `externalMutation:false`. Recovery route strings such as `places-rewards:offers` are labels, never production calls. Injected custom connectors are trusted code and must declare sandbox mode; HTTP cannot register plugins. Real provider execution requires a separate reviewed adapter and provider-specific credentials, idempotency and non-production validation.

All money is modeled USD opportunity. Leaks can overlap, so totals may overstate opportunity. Measurement requires the same business, unchanged effective ticket, every baseline signal and non-older evidence. It measures modeled differences, not causation or recovered cash. `verifiedRecoveredRevenue` is always `null`.

## Safety and verification

Every write requires an `Idempotency-Key`; opportunity changes also require a current `version`. Approval binds one payload hash, connector, reviewer, reason and expiry. `/recover` only creates pending actions. Execution saves intent before dispatch and saves a receipt afterward. Errors, invalid receipts, timeouts and interrupted execution require explicit reconciliation; no automatic retry exists. A confirmed non-execution requires a fresh approval before another attempt.

`npm run test:revenue` syntax-checks all Revenue Engine JavaScript and runs every Revenue Engine test. Independent CI runs tests and the synthetic demo on Node 20/22/24, Linux and Windows. Existing `npm run test:app` inspects a production Laravel environment and is deliberately excluded.

See [API.md](API.md) for routes, [contracts.d.ts](contracts.d.ts) for consumer types, and [OPERATIONS.md](OPERATIONS.md) for persistence, backup and recovery.

Existing `index.js`, `pipeline.js`, `providers.js` and `workflow.js` remain diagnostic/planning libraries. Use `application.js` and the HTTP API for durable operations and approval enforcement. The TypeScript prototype in `src/revenue-engine` is not the operational entrypoint.
