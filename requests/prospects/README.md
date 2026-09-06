# Public Prospect Intake

This directory is the public-research intake boundary for the Places Rewards Autonomous Company OS.

One JSON file represents one evidence-backed local-business prospect. The server converts valid files into private commercial opportunity state. Public prospect files must contain only business/public-web information. Do not place passwords, private customer data, non-public personal information, bank data, health data, or merchant-internal metrics here.

## Contract

```json
{
  "schemaVersion": 1,
  "requestType": "public_prospect",
  "requestId": "20260907-example-business",
  "observedAt": "2026-09-07T12:00:00Z",
  "business": {
    "name": "Example Business",
    "city": "Mentor, OH",
    "website": "https://example.com",
    "category": "restaurant",
    "publicContact": {
      "email": "hello@example.com",
      "phone": "+1-440-555-0100",
      "sourceUrl": "https://example.com/contact",
      "observedAt": "2026-09-07T12:00:00Z"
    }
  },
  "fitScore": 72,
  "evidence": [
    {
      "url": "https://example.com/page",
      "observedFact": "Public website has no visible loyalty or repeat-visit program.",
      "observedAt": "2026-09-07T12:00:00Z"
    }
  ],
  "gaps": [
    {
      "category": "loyalty_gap",
      "confidence": 0.75,
      "evidenceIndexes": [0],
      "recommendedFix": "Demonstrate a measurable repeat-visit loyalty workflow."
    }
  ],
  "recommendedEntryOffer": "Repeat-Visit Revenue Engine",
  "notes": "Observed facts must be separated from assumptions. Do not claim a monetary loss unless supported by merchant-owned data."
}
```

`business.publicContact` is optional. Include only contact information the business itself publishes or another clearly public business directory publishes. `sourceUrl` is required whenever an email or phone is supplied so the contact remains traceable. Do not infer email addresses from names or domains.

## Allowed gap categories

- `reputation`
- `booking_friction`
- `loyalty_gap`
- `referral_gap`
- `event_retention`
- `reactivation_signal`
- `followup_friction`

## Rules

- `requestId` must match the filename without `.json`.
- `fitScore` is 0-100 and measures sales fit, not guaranteed revenue.
- Every gap must cite one or more evidence indexes.
- Public evidence must be a concrete observed fact from the cited source.
- Absence of a visible feature is a sales hypothesis, not proof of lost revenue.
- Public contact data must be observed, source-linked and business-facing; never guess or synthesize an address.
- The intake worker never sends outreach and never writes production campaign data.
