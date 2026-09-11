# Revenue Engine v1

An isolated revenue-leak intelligence layer. It does **not** mutate the Places Rewards application or production data.

## Pipeline

Discover → Diagnose → Quantify → Prescribe → Demonstrate → Close → Recover → Measure

## Current slice

`RevenueEngine.diagnose()` accepts normalized business signals and produces an evidence-bearing opportunity report with modeled monthly/annual loss, confidence, recommended remediation, and an optional Places Rewards module mapping.

`RevenueSignalIngestor` converts source-specific business metrics into normalized signals.

`RevenueDiscoveryEngine` ranks businesses by modeled revenue opportunity, leak breadth, and diagnostic confidence so the system can prioritize the strongest prospects first.

Initial detectors:

- missed-call leakage
- dormant-customer/reactivation leakage
- repeat-purchase/retention leakage

## Subscription boundary

Revenue Engine is intentionally separated from the base Places Rewards feature set and is controlled through explicit entitlements. This lets billing/subscription code expose Revenue Engine capabilities only to selected higher tiers without coupling the engine to Stripe or the production application.

Current capability model:

- `disabled`: no Revenue Engine access
- `growth`: diagnostics only
- `pro`: diagnostics + automated discovery + advanced reporting
- `enterprise`: diagnostics + discovery + continuous monitoring + recovery automation + advanced reporting

These are internal capability tiers, not final public pricing names. Production billing integration should map actual merchant plans to these entitlements later.

## Isolation boundary

Places Rewards remains a downstream recovery option. Revenue Engine communicates through explicit adapters/API contracts rather than importing or modifying application internals. Remediation actions remain approval-gated until separately authorized.

## Next build slices

1. Source adapters for public/local-business signals and first-party business data.
2. Lead follow-up and conversion detectors.
3. Reputation/local-search detectors.
4. Evidence/report generator.
5. Recovery-plan router to Places Rewards and other remediation systems.
6. Before/after recovered-revenue measurement.
7. Production subscription-plan → Revenue Engine entitlement mapping.
