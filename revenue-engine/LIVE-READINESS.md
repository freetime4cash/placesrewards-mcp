# Release readiness

## Verified local release

The local dashboard supports persisted business records, discovery/evidence imports, lifecycle planning/closing, prospect follow-up, explicit approvals, simulated recovery, modeled measurement, outreach drafts, manual callbacks, reports and audit. Launcher regression coverage includes repeated and concurrent launch, crash-lock recovery, credential-safe listener verification and browser-opening failure. The browser sign-in survives page refresh and Lock removes tab access.

Run `npm run test:revenue`, `npm run demo:revenue` and `npm run demo:callbacks`. All mutations are isolated from Places Rewards. No sample business data is automatically loaded into the user's working store.

## Not yet an online/live-delivery release

Passing the local tests does not validate a public deployment or real delivery. No hosting destination, public domain, online identity provider or real execution credentials have been configured. Vapi/Make remain deferred.

The owner must first choose local operator-only use or customer/team online access and identify the hosting account/domain/budget. An online release then needs:

- A separate Revenue Engine deployment and datastore, HTTPS, managed secrets and a hosting-aware storage/backup/restore strategy.
- Online sign-in, account provisioning/revocation, tenant membership and roles, session/CSRF controls and appropriate public request limits. The desktop administrator key is not an internet login system.
- Explicitly configured real execution adapters for the selected providers, provider idempotency and reconciliation, verified delivery callbacks and opt-out handling. Included adapters still return simulated receipts.
- End-to-end tests on the deployed environment with controlled contacts, tenant isolation, expired/revoked approvals, delivery failures, restart/recovery, backup restore and rollback.
- A limited approved pilot before broad access. Verified recovered cash requires independent evidence; modeled improvement and reported bookings do not establish it.

No environment-variable change, branch merge, tunnel or public proxy makes these steps complete. Local use and manually performed callback work are available while online activation is prepared.
