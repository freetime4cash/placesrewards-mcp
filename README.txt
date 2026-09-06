Places Rewards MCP control plane repository.

This repository contains the production server-runtime control plane, orchestration agents, campaign request bridge, reconciliation tooling, revenue engine, and automation scripts for Places Rewards.

The primary source-of-truth branch is `server-runtime`.

Important production principles:
- preserve the live Laravel application and its security controls
- use the GitHub/server-runtime bridge for autonomous work
- keep merchant analytics and sensitive operational data out of Git
- treat modeled revenue estimates separately from observed evidence
- validate changes before production execution
- use protected-write approval gates for destructive or high-risk changes

Active strategic programs include the Northeast Ohio Treasure Hunt, 363 Foundation / Radio Cigars demos, Mystery Rewards, Revenue Leak Detection + Recovery, and merchant acquisition automation.
