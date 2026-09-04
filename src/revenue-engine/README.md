# Revenue Engine v1

An isolated revenue-leak intelligence layer. It does **not** mutate the Places Rewards application or production data.

## Pipeline

Discover → Diagnose → Quantify → Prescribe → Demonstrate → Close → Recover → Measure

## Current slice

`RevenueEngine.diagnose()` accepts normalized business signals and produces an evidence-bearing opportunity report with modeled monthly/annual loss, confidence, recommended remediation, and an optional Places Rewards module mapping.

Initial detectors:

- missed-call leakage
- dormant-customer/reactivation leakage
- repeat-purchase/retention leakage

## Isolation boundary

Places Rewards remains a downstream recovery option. Revenue Engine must communicate through explicit adapters/API contracts rather than importing or modifying application internals. All remediation actions should remain approval-gated until separately authorized.

## Next build slices

1. Signal ingestion + normalization adapters.
2. Lead follow-up and conversion detectors.
3. Reputation/local-search detectors.
4. Opportunity scoring and prospect ranking.
5. Evidence/report generator.
6. Recovery-plan router to Places Rewards and other remediation systems.
7. Before/after recovered-revenue measurement.
