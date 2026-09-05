# Revenue Engine Runtime

A separately gated premium revenue-intelligence subsystem for Places Rewards.

## Commercial boundary

The Revenue Engine is not required for the base Places Rewards loyalty ecosystem. Subscription/billing code should resolve a Revenue Engine tier and pass that entitlement into this runtime. The runtime does not read Stripe plans directly.

### Capability tiers

- `disabled`: no Revenue Engine access.
- `growth`: merchant diagnostics and recovery planning.
- `pro`: diagnostics + automated business discovery/ranking + advanced reports.
- `enterprise`: Pro + continuous monitoring + recovery automation entitlement.

Names are internal capability bundles and can be mapped to different customer-facing plans later.

## Runtime flow

Discover → Normalize → Diagnose → Quantify → Rank → Prescribe → Report → Recover → Measure

## Current detectors

- missed calls
- uncontacted lead follow-up
- dormant-customer reactivation
- repeat-purchase/retention gap
- reputation weakness

All loss numbers are modeled estimates based on available signals. They must be identified as estimates until validated against merchant source data.

## Safety / isolation

- No production Places Rewards mutation.
- No direct Stripe dependency.
- Recovery actions are plans only; they remain approval-gated.
- Places Rewards is a downstream recovery route for applicable leaks, not an internal dependency.
- External discovery adapters should collect lawful/public or explicitly authorized data and retain evidence/provenance for every signal.

## Verification

Run:

```bash
npm run test:revenue
```

## Next integration boundary

External source adapters should output records shaped like:

```js
{
  id, name, industry, averageTicket, source, observedAt,
  metrics: { /* normalized evidence-backed metrics */ }
}
```

This keeps Google/local-search data, CRM data, telephony data, merchant uploads, and future providers outside the diagnostic core.
