# Use Revenue Engine

## Open your workspace

On Windows, open the repository's `revenue-engine` folder in File Explorer and double-click **Open Revenue Engine.cmd**. A service window and your default browser open. Keep the service window open while using the dashboard.

Alternatively run `npm run open:revenue` from the repository. Node.js 20 or newer is required; no installation of third-party packages is needed.

The default address is http://127.0.0.1:4311/. This is a local application, accessible on this computer only. The launcher signs its browser tab in automatically. Running it again reconnects to the same service instead of starting a second writer. Page refresh preserves sign-in in tab-scoped sessionStorage; Lock clears it. Run the launcher again to reopen after locking. The key fragment is removed immediately; do not share the launch URL before it disappears.

A private `launcher-session.json` in the data directory lets the launcher reopen the running instance. It contains a local access credential: protect it using your OS account permissions, never commit/share it, and exclude it from exported business data. Before reusing the credential, the launcher verifies a random cryptographic challenge against the listener; the credential is not sent to an unproven service. Restart rotates the key.

If a crashed process left `writer.lock`, the launcher removes that lock only when the OS confirms that its recorded PID no longer exists. It serializes recovery, preserves saved state, and never removes a live writer's lock. Ambiguous ownership or malformed metadata fails closed with an explanation.

The launcher always uses the `local-workspace` tenant and `local-owner` administrator, independently of custom API credentials. It never adopts a production identity. For separate operators/reviewers or existing API tenants, configure `start:revenue` using README and enter the appropriate key on the dashboard's sign-in screen. An administrator can approve and operate, but approval never happens automatically.

## Everyday workflow

1. **Prospects:** add a business. Enter known metrics and leave unknowns blank. Record when and where your evidence was observed. Optional rates are fractions from 0 to 1. Or use Import records for an authorized JSON evidence batch (up to 100 records, format in API.md).
2. Open the business. Progress through **Diagnose → Quantify → Build plan → Prepare demonstration**. Inspect evidence and results at each step.
3. **Record deal decision:** won, lost or deferred, with optional commercial terms and notes. This records agreement metadata without billing.
4. For won deals, **Prepare recovery actions**. The resulting tasks are pending review.
5. **Approvals:** review the exact action, give a reason and expiration, and save the approval. **Run approved simulation** is a separate explicit step. The server revalidates the action and approval before every execution.
6. Use **Measure results** to enter complete updated evidence. The business and average ticket remain fixed. Measurement finalizes a modeled before/after comparison, not verified recovered cash.

Outreach drafts, follow-up scheduling, claim/release, deferred reopening and uncertain-execution reconciliation are available on the business page. Writes use current versions; if another tab has changed an item, refresh and review before submitting again.

## Manual callbacks without a phone provider

Open any prospect and choose **Add manual callback**. Enter the requested phone number in international format, contact name (optional), reason and due time. This records a task, not a call. Review and approve it from **Callbacks** before acting. After making a call yourself, record reached/booked/no answer/do not call. No answer requires a future review and fresh approval. Do not call suppresses that number across the tenant. No code dials or texts.

Vapi and Make setup is deferred and not required for manual work.

## Reports, persistence and recovery

Open a business to view readable report sections. Expand the sections you want to print, then choose **Print this view** and your browser's Save as PDF destination. **Download report** exports the report contract as JSON. Estimates may overlap and are not verified cash.

Data persists in `revenue-engine-data` beside the repository package.json by default. A restart retains records but rotates the launcher key. To back up, stop the service with Ctrl+C, then copy the entire folder to a private location. To restore, stop first and replace that folder with a known-good backup. Never put this state into production. See OPERATIONS.md for stale-lock and corruption handling.

If a save loses its response, leave its form open and retry without changing it. The same request key is reused, preventing a repeated dispatch. Do not start another command while that result is unknown. A server/version error requires reviewing current state. No client automatically retries mutations.

## Troubleshooting

- **Reopening:** double-click the launcher again; it reconnects automatically. An older pre-fix server needs one restart before this works. A different program occupying the port still needs separate attention.
- **Browser did not open:** check that a desktop browser is available and restart the launcher. An environment-configured server also accepts your manually entered access key.
- **Authentication required:** run the launcher again. Normal refresh stays signed in; Lock and server restart invalidate previous access. Browser privacy settings that disable sessionStorage fall back to memory-only access.
- **No opportunities:** the workspace starts empty; demos and browser checks do not populate your working data.
- **Feature access error:** viewers cannot edit; operators cannot approve; Growth cannot access portfolio/executive reports; Enterprise is required for recovery simulation.

The app is not publicly hosted, performs no outbound email/SMS/calls or billing, and imports no Places Rewards production entrypoints. Real execution adapters and deployment require their own configuration and validation. All included transports remain simulated.
