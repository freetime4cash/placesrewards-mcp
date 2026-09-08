# Operations and recovery

## Isolation

Run this service in its own process with Revenue Engine-only credentials and a dedicated local directory. It never imports `lib/`, production server/worker code, Laravel tools, billing or campaign execution. `npm start` still starts the original Places Rewards service. No production deployment or merge is required for sandbox integration. The import-graph test guards this boundary.

The optional `sync:vapi` command is a separate read-only bridge. It contacts Vapi only when explicitly run without `--file`, then posts minimized call events to the local service. It never starts outbound calls. Callback records and do-not-call suppressions are retained with the same transactional snapshot; include them in backups. `demo:callbacks` makes no network calls and requires no accounts.

The HTTP listener is loopback-only. Do not expose it through a public reverse proxy without a separately reviewed deployment architecture. Current authentication is static server configuration; there is no user management/billing integration. Dashboard/report endpoints supply data contracts, not a browser UI.

## Persistent store

`state.json` has service marker `revenue-engine-sandbox`, schema version 1, revision, opportunities, idempotent command responses, and audit history. Changes run serially on a clone and publish only after atomic temporary-file write, file fsync and rename. POSIX also syncs the containing directory; Windows Node cannot fsync directories. Local filesystems only; network filesystem atomicity is not supported.

`writer.lock` is created exclusively and held for the process lifetime. A second writer fails startup. The lock records process ID/start time. There is no automatic stale-lock stealing. Graceful shutdown drains requests/receipts before closing storage and removing the lock. A crash leaves the lock for operator inspection.

The store caps serialized state at 32 MiB and 10,000 opportunities (whichever is reached first). Commands retain response snapshots, so the byte limit may arrive well before the opportunity limit. Reaching capacity fails writes without partial commits. There is no online archive, automatic eviction or retention pruning. Never delete idempotency history from an active store; that could change retry behavior. For larger deployments, implement a reviewed database store with transactional commands and execution intents before increasing throughput.

Corrupt JSON, unknown schema/service markers and invalid opportunity envelopes fail startup without replacing existing data. Write/sync failures fail the store closed because commit outcome may be uncertain. Process memory and API responses use the same JSON representation as disk. Local trusted filesystem access is a security boundary; use OS permissions/ACLs and encrypted storage for retained merchant/contact information. Audit records are application history, not cryptographically tamper-proof records.

## Backup and restore

1. Stop intake and shut down gracefully; confirm the process exited and the lock is gone.
2. Copy `state.json` to protected backup storage. Record its checksum, schema version and current revision. Retain command history, approvals and execution records together.
3. Restore only while the service is stopped into a dedicated Revenue Engine directory. Keep the original snapshot until validation succeeds. Do not merge JSON documents or copy production state into this directory.
4. Start with sandbox settings. Startup validates the snapshot and converts interrupted `executing` actions into `uncertain` before listening. Inspect health, audit and pending executions.

Backups are operator-managed; this application does not upload data or backups anywhere. Schema migration is deliberately explicit: unsupported versions fail closed; no automatic migration currently exists.

## Crash or unknown execution

1. Inspect the PID/start time in `writer.lock` and verify no process owns this store. Stop the owner if still running. Never remove a live writer's lock.
2. Back up the complete store. Remove only the stale lock after confirming the owner is gone. Orphan `state-*.tmp` files are not committed state; preserve for investigation and clean them offline.
3. Restart. Interrupted intents become `uncertain` with an audit event; nothing is redispatched.
4. Read the exact action and execution ID. Review provider evidence. With the bundled connector all activity is simulation, but an unresolved result still requires review.
5. An approver calls `/reconcile` with evidence and `simulated` or `not_executed`. The latter returns to pending approval. Any next execution needs a new approval and fresh request key/version.

Do not restore an old backup and blindly resume executions: restored intent/command history can be behind already completed actions. Reconcile the difference offline before running requests. The application guarantees no automatic retry, not exactly-once execution across arbitrary external systems or rolled-back backups.

## Logging and failures

Structured request logs allowlist request ID, event, duration, status, error code and authenticated tenant/actor IDs. They omit URLs, body, credentials, contact copy and raw provider exceptions. Audit entries retain operation, actor, opportunity and version; approval/reconciliation detail stays in durable action history. Store files therefore contain sensitive business data even though request logs do not.

`GET /health` returns 503 if storage is unavailable. On startup, the CLI emits `revenue.start_failed` with a sanitized code. Logging failures cannot repeat committed operations. HTTP body/concurrency limits protect the local service; this is not an internet-scale rate limiter.

## Acceptance checks

Run `npm run test:revenue` and `npm run demo:revenue`. Tests cover the full HTTP lifecycle; restart persistence; atomicity; tenant, role and tier boundaries; duplicate requests; concurrent claims/execution; expired/revoked/changed approvals; provider timeout and invalid receipts; reconciliation; storage corruption/capacity/failure; evidence validation; outreach; redacted logging; and isolated imports. CI runs Node 20/22/24 on Linux/Windows without production secrets or external API calls.

Real provider adapters, production deployment, billing, verified-cash reconciliation, online retention, distributed storage and a browser dashboard are separate integrations. The current service supplies the operational workflow and dashboard/report data contracts for a safe sandbox release.
