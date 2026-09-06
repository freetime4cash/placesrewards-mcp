# Places Rewards Autonomous Company OS

## Objective

Operate the Places Rewards ecosystem from durable system state instead of relying on the owner's memory or attention to preserve workflow continuity.

The control plane must continuously answer five questions:

1. What is actually true right now?
2. What is inconsistent, broken, duplicated or stale?
3. Which issue or opportunity has the highest economic or operational value?
4. What is the smallest safe action that moves the system forward?
5. What evidence proves the action worked?

## Source-of-truth rule

The `server-runtime` branch of `freetime4cash/placesrewards-mcp` is the control-plane source of truth. Production application state is observed from `app.placesrewards.com` through the Agent API and server-side Laravel inspection. Runtime ledgers live under `data/` and are deliberately excluded from Git.

Git is an audit trail of meaningful control-plane and campaign state transitions. It must not be used as a minute-by-minute telemetry database.

## Reconciliation-first rule

Before adding overlapping functionality, the system should reconcile existing implementations, branches, requests, campaign results, live routes, tools and database state. A newly detected discrepancy becomes an autonomous repair objective once. The autopilot ledger prevents unchanged failures from being re-enqueued repeatedly.

## Attention-independent workflow

The owner is not expected to remember task order, unfinished work or follow-up timing. The operating loop is:

`Observe -> Reconcile -> Prioritize -> Route -> Prepare -> Validate -> Execute within policy -> Measure -> Persist state -> Repeat`

Jobs are resumable. Failures remain represented as system state until they are resolved. New work should not silently replace unresolved high-priority work.

## Revenue priority

After stability and security gates, work is ranked by:

- probability of producing near-term revenue;
- recurring revenue potential;
- merchant ROI impact;
- ability to unlock multiple campaigns or merchants;
- reduction in manual operational burden;
- network effects and reusable data advantage;
- implementation risk and reversibility.

Revenue Leak Detection, merchant conversion, Northeast Ohio Treasure Hunt conversion, 363 ecosystem demos, Mystery Rewards and Places Rewards subscription expansion are treated as coordinated revenue subsystems rather than unrelated projects.

## Autonomy boundaries

Read-only inspection, analysis, prioritization, reconciliation job creation and other reversible control-plane operations run automatically.

Safe writes may run automatically when the runtime policy classifies them as `safe_write` and the executing agent has sufficient autonomy.

Protected production writes and destructive operations retain explicit safeguards. Broad authorization does not imply permission to expose credentials, weaken security boundaries or perform irreversible destructive operations.

## Discrepancy handling

`lib/discrepancy.js` normalizes volatile timestamps and generates semantic fingerprints for observed failures. `scripts/autopilot.mjs` scans campaign results, records active/resolved discrepancy state, and converts newly observed failures into repair objectives. `scripts/semantic-result-diff.mjs` prevents timestamp-only observation refreshes from generating Git commits.

The minute cron remains useful as an observation frequency, but unchanged observations no longer create new semantic events.

## Definition of done

A feature is not complete because code exists. Completion requires, where applicable:

- live capability present;
- intended user or merchant path reachable;
- authorization and tenancy verified;
- non-destructive tests pass;
- rollback path exists for production changes;
- campaign/demo links resolve correctly;
- analytics can measure the intended outcome;
- revenue or merchant-ROI hypothesis has a measurable KPI;
- control-plane state no longer reports the discrepancy.

## Current first reconciliation target

The live campaign bridge reports that `placesrewards:install-363-demo` is not registered while the 363 Foundation demo installation workflow continues to be probed. This should be resolved as a capability/source-of-truth issue rather than continually re-recorded as a fresh failure.
