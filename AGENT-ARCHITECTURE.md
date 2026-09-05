# Places Rewards Permanent AI Architecture

Version 1.0.0

## Purpose

This runtime is the permanent agent control plane for the Places Rewards ecosystem. It is designed to extend the existing system safely rather than replace it. It treats placesrewards.com, app.placesrewards.com, dir.placesrewards.com, merchant campaigns, Mystery Rewards, Revenue Engine, analytics and the merchant network as one connected business system.

## Operating loop

Objective -> PR Orchestrator -> Specialist routing -> Architecture/Revenue gates -> Domain planning -> Build -> Security -> Adversarial QA -> Deployment -> Analytics -> Optimization -> Opportunity discovery.

Production-impacting writes remain subject to the existing risk policy, isolated patch workflow, validation and rollback behavior.

## Permanent agents

Core/control: command (PR Orchestrator), architecture_guardian, monetization_controller, revenue_architect.

Business/intelligence: opportunity_discovery, revenue_leak, local_intelligence, business_twin, rewards_intelligence, analytics_roi, simulation.

Campaign/growth: campaign_architect, mystery_engine, growth, network, automation.

Sales/domain: sales, demo_factory, treasure_hunt, three63.

Engineering/protection: build, backend_engineer, frontend_product, integration, test, qa_adversarial, security, deployment, documentation, optimization, demo.

## Domain packs

### Northeast Ohio Treasure Hunt

The treasure_hunt agent owns merchant prioritization, post-hunt conversion campaigns, Founding Merchant offers, Mystery migration and network expansion. It automatically routes with campaign_architect, sales, revenue_architect and analytics_roi.

### 363 ecosystem

The three63 agent owns 363 Empire, 363 Foundation and Radio Cigars campaign/demo monetization. It automatically routes with campaign_architect, mystery_engine, revenue_architect and demo_factory.

## Skill model

Skills are reusable and live in `lib/skills.js`. Agents consume shared skills instead of duplicating logic. Skill families include core, protection, build, operations, business, intelligence, campaign, analytics, growth and sales.

Every major objective is evaluated through two permanent lenses:

1. Ecosystem awareness: does the work strengthen or risk another Places Rewards component?
2. Revenue awareness: does it increase merchant ROI, Places Rewards revenue, recurring revenue, automation, retention, network effect or data advantage?

## Autonomy and safety

L0 Observe: analysis/read only.
L1 Recommend: specifications and recommendations.
L2 Prepare: planning, intelligence, non-destructive inspection and preparation.
L3 Execute Safe: safe writes and isolated preparation where policy permits.
L4 Production: production-impacting changes only through explicit protected-write safeguards.

The runtime policy continues to require approval for protected production writes and destructive actions. Build patches retain isolated proposal, validation and rollback behavior.

## Runtime interfaces

`POST /agent/run` queues an objective through the PR Orchestrator.
`POST /agent/worker` processes queued work.
`POST /agent/cron/worker` processes work through the cron-secret gate.
`GET /agent/status` reports job state.
`GET /agent/agents` reports the registered permanent agent organization and assigned skills.
`POST /agent/approve/:id` handles jobs waiting for approval.

## Verification

Run:

`npm run test:architecture`

This verifies permanent agent registration, skill-registry size, Treasure Hunt routing, 363 routing and engineering safety routing.

Existing application diagnostics remain available through:

`npm run inspect:app`
`npm run test:app`

## Design rule

Extend before replacing. Reuse before duplicating. Measure before optimizing. No agent may treat a feature as successful merely because code exists; success must ultimately connect to a measurable business or system outcome.
