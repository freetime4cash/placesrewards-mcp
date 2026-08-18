# Campaign Requests

Each approved campaign request is one JSON file:

`<requestId>.json`

Required top-level fields:

- `requestId`
- `approved: true`
- `campaignName`
- `steps`

Each step contains:

- `name`
- `method`
- `path`
- optional `body`

The server refreshes the live Agent API tool catalog before execution.
A request cannot call a method/path not present in the current discovered
campaign-relevant Agent API tools.

Processed results are written to:

`results/campaigns/<requestId>.json`

The worker is idempotent: a completed request ID is not executed twice.
