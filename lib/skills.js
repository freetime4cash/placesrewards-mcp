export const SKILLS = Object.freeze({
  ecosystem_awareness: { family: "core", purpose: "Treat placesrewards.com, app.placesrewards.com, dir.placesrewards.com, campaigns, Revenue Engine, Mystery Rewards, analytics and merchant network as one ecosystem." },
  revenue_awareness: { family: "core", purpose: "Score work for merchant ROI, Places Rewards revenue, recurring revenue, scalability, automation, retention, network effect and data advantage." },
  architecture_impact: { family: "protection", purpose: "Map routes, controllers, models, services, tables, APIs, UI and integrations before production-impacting changes." },
  rollback_design: { family: "protection", purpose: "Require reversible releases, backups, validation and rollback paths." },
  security_audit: { family: "protection", purpose: "Validate authentication, authorization, CSRF, tenancy, secrets, input validation and rate limits." },
  regression_testing: { family: "protection", purpose: "Test existing behavior and edge cases before accepting a change." },
  laravel_development: { family: "build", purpose: "Laravel/PHP implementation, service design, queues, jobs, migrations and application conventions." },
  api_development: { family: "build", purpose: "Design and implement stable internal and external API contracts and webhooks." },
  database_design: { family: "build", purpose: "Schema design, migrations, indexes, integrity, tenancy boundaries and query efficiency." },
  frontend_product: { family: "build", purpose: "Responsive merchant/customer UX, shared components, accessibility and conversion-oriented flows." },
  integration_engineering: { family: "build", purpose: "Integrate Stripe, WordPress, MCP, external APIs, webhooks and partner systems." },
  deployment: { family: "operations", purpose: "Git, release sequencing, migrations, backups, environment checks and rollback." },
  dependency_mapping: { family: "operations", purpose: "Decompose objectives, map dependencies and define acceptance criteria." },
  state_management: { family: "operations", purpose: "Persist jobs, retries, approvals, outputs and execution state." },
  documentation: { family: "operations", purpose: "Maintain runbooks, architecture maps, changelogs, API docs and campaign specifications." },
  revenue_modeling: { family: "business", purpose: "Pricing, packaging, subscriptions, setup fees, sponsorships, performance fees and unit economics." },
  merchant_roi: { family: "business", purpose: "Quantify merchant value, payback, LTV, retention value and attributable/recovered revenue." },
  opportunity_scoring: { family: "intelligence", purpose: "Rank opportunities by value, confidence, effort, urgency, strategic fit and monetization potential." },
  revenue_leak_detection: { family: "intelligence", purpose: "Detect churn, abandoned rewards, missed referrals, weak conversion, dormant customers and other recoverable revenue." },
  customer_behavior: { family: "intelligence", purpose: "Analyze lifecycle, cohorts, visits, redemptions, referrals, reactivation and engagement." },
  merchant_intelligence: { family: "intelligence", purpose: "Build a business profile using category, offers, audience, history, campaigns, economics and opportunities." },
  local_market_intelligence: { family: "intelligence", purpose: "Find and rank local businesses, events, partnerships, categories and community opportunities." },
  campaign_generation: { family: "campaign", purpose: "Generate complete campaigns with audience, offer, trigger, journey, automation, economics and measurement." },
  segmentation: { family: "campaign", purpose: "Segment customers and merchants by behavior, value, lifecycle and campaign relevance." },
  reward_design: { family: "campaign", purpose: "Design profitable incentives and redemption rules that influence behavior without destroying margin." },
  mystery_mechanics: { family: "campaign", purpose: "Design Mystery Rewards, Drops, Deals, Discovery, Referrals, Recovery and Hunts." },
  attribution: { family: "analytics", purpose: "Separate observed, attributed, estimated incremental, recovered and projected revenue." },
  experimentation: { family: "analytics", purpose: "Design A/B tests and optimization loops with measurable success criteria." },
  acquisition: { family: "growth", purpose: "Create merchant and consumer acquisition loops and channel strategy." },
  retention: { family: "growth", purpose: "Improve repeat visits, reactivation, loyalty, lifecycle messaging and churn prevention." },
  referrals: { family: "growth", purpose: "Create measurable customer and merchant referral loops." },
  network_effects: { family: "growth", purpose: "Increase cross-business discovery, merchant density, consumer utility and directory value." },
  sales_conversion: { family: "sales", purpose: "Qualify prospects, select demos, personalize outreach, handle objections, close and upsell." },
  demo_engineering: { family: "sales", purpose: "Create safe, persuasive, personalized demonstrations tied to prospect outcomes." },
  simulation: { family: "analytics", purpose: "Model conservative, expected and aggressive campaign economics before launch." }
});

export function skillNames(...names) {
  return names.filter(name => Object.hasOwn(SKILLS, name));
}

export function describeSkills(names = []) {
  return names.filter(name => SKILLS[name]).map(name => ({ name, ...SKILLS[name] }));
}
