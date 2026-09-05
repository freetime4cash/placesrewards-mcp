import { describeSkills, skillNames } from "./skills.js";

class PlanningAgent {
  constructor({ name, mission, maxAutonomy = 2, skills = [], outputs = [] }) {
    this.name = name;
    this.mission = mission;
    this.maxAutonomy = maxAutonomy;
    this.skills = skillNames(...skills);
    this.outputs = outputs;
  }

  async run(context) {
    return {
      ok: true,
      summary: `${this.name} completed planning/intelligence work for: ${context.objective}`,
      data: {
        agent: this.name,
        mission: this.mission,
        skills: describeSkills(this.skills),
        expectedOutputs: this.outputs,
        objective: context.objective,
        input: context.input ?? {},
        actions: [{ name: `${this.name}_analysis`, risk: "read", details: { execute: true } }]
      }
    };
  }
}

const DEFINITIONS = [
  ["architecture_guardian", "Protect the existing Places Rewards ecosystem and gate risky architectural changes.", 2,
    ["ecosystem_awareness","architecture_impact","rollback_design","security_audit","regression_testing","dependency_mapping"],
    ["impact map","risk classification","compatibility constraints","rollback requirements"]],
  ["revenue_architect", "Make builds economically valuable to merchants and monetizable by Places Rewards.", 2,
    ["ecosystem_awareness","revenue_awareness","revenue_modeling","merchant_roi","opportunity_scoring"],
    ["revenue score","pricing/packaging implications","merchant ROI hypothesis","upsell path"]],
  ["monetization_controller", "Prioritize work by revenue potential, recurring value, scalability and implementation economics.", 2,
    ["revenue_awareness","revenue_modeling","merchant_roi","opportunity_scoring","network_effects"],
    ["0-100 revenue score","priority recommendation","monetization path","economic risks"]],
  ["revenue_leak", "Detect, quantify and prescribe recovery for merchant revenue leaks.", 2,
    ["revenue_leak_detection","customer_behavior","merchant_roi","campaign_generation","attribution","opportunity_scoring"],
    ["leak inventory","estimated value","confidence","recommended recovery action","measurement plan"]],
  ["campaign_architect", "Design complete measurable merchant and network campaigns.", 2,
    ["campaign_generation","segmentation","reward_design","merchant_roi","attribution","simulation","retention","referrals"],
    ["campaign spec","customer journey","offer economics","automation triggers","KPIs"]],
  ["mystery_engine", "Own Mystery Rewards, Drops, Deals, Discovery, Referrals, Recovery and Hunt mechanics.", 2,
    ["mystery_mechanics","reward_design","campaign_generation","retention","referrals","network_effects","merchant_roi"],
    ["mystery mechanic","eligibility rules","reward pool","economics","engagement loop"]],
  ["growth", "Increase merchant/customer acquisition, activation, retention, referrals and network effects.", 2,
    ["acquisition","retention","referrals","network_effects","experimentation","attribution"],
    ["growth loop","activation target","retention mechanism","experiment backlog"]],
  ["local_intelligence", "Find and rank local businesses, partnerships, sponsors, events and market opportunities.", 2,
    ["local_market_intelligence","merchant_intelligence","opportunity_scoring","sales_conversion","network_effects"],
    ["prospect set","priority score","fit rationale","next best action"]],
  ["sales", "Convert qualified businesses using the shortest outcome-focused path to paid subscription.", 2,
    ["sales_conversion","merchant_intelligence","demo_engineering","revenue_modeling","merchant_roi"],
    ["qualification","demo choice","offer","objection plan","close path","follow-up"]],
  ["demo_factory", "Create safe personalized demonstrations tied to merchant outcomes and active offers.", 3,
    ["demo_engineering","campaign_generation","mystery_mechanics","sales_conversion","merchant_roi"],
    ["demo scenario","seed data plan","links/checklist","conversion narrative"]],
  ["treasure_hunt", "Own Northeast Ohio Treasure Hunt merchant conversion and migration into recurring Places Rewards revenue.", 2,
    ["local_market_intelligence","merchant_intelligence","campaign_generation","mystery_mechanics","sales_conversion","network_effects","merchant_roi"],
    ["merchant priority","post-hunt campaign","founding merchant offer","conversion journey","network expansion"]],
  ["three63", "Own 363 Empire, 363 Foundation and Radio Cigars ecosystem campaigns, demos and monetization.", 2,
    ["merchant_intelligence","campaign_generation","mystery_mechanics","demo_engineering","network_effects","revenue_modeling","sales_conversion"],
    ["ecosystem campaign","audience journey","sponsor/giveaway plan","demo plan","monetization"]],
  ["rewards_intelligence", "Optimize reward economics and behavioral effectiveness.", 2,
    ["reward_design","customer_behavior","merchant_roi","segmentation","experimentation"],
    ["reward recommendation","margin guardrails","redemption target","test plan"]],
  ["network", "Grow cross-business discovery and merchant-network compounding value.", 2,
    ["network_effects","local_market_intelligence","merchant_intelligence","acquisition","referrals","attribution"],
    ["merchant graph opportunity","cross-promotion path","density strategy","network KPI"]],
  ["automation", "Design reliable event-driven lifecycle workflows and intervention triggers.", 2,
    ["dependency_mapping","state_management","campaign_generation","retention","referrals","api_development"],
    ["trigger map","workflow states","retry/idempotency requirements","failure handling"]],
  ["analytics_roi", "Measure campaign and merchant economics with disciplined attribution.", 2,
    ["attribution","merchant_roi","customer_behavior","experimentation","simulation"],
    ["KPI definition","attribution class","ROI model","dashboard requirements"]],
  ["backend_engineer", "Implement Laravel backend capabilities safely.", 3,
    ["laravel_development","api_development","database_design","architecture_impact","rollback_design","regression_testing"],
    ["implementation plan","files/services","migration/API impact","tests","rollback"]],
  ["frontend_product", "Implement conversion-oriented accessible customer and merchant UX.", 3,
    ["frontend_product","ecosystem_awareness","campaign_generation","sales_conversion","regression_testing"],
    ["UX flow","component plan","states/errors","responsive/accessibility checks"]],
  ["integration", "Connect Places Rewards safely to billing, WordPress, MCP and external systems.", 3,
    ["integration_engineering","api_development","security_audit","rollback_design","regression_testing"],
    ["integration contract","auth/secrets plan","webhook/idempotency plan","tests"]],
  ["qa_adversarial", "Attempt to break changes and reject unsafe regressions.", 2,
    ["regression_testing","security_audit","architecture_impact","attribution"],
    ["edge-case matrix","regression results","security failures","release verdict"]],
  ["security", "Protect authentication, authorization, tenancy, data and production boundaries.", 2,
    ["security_audit","architecture_impact","rollback_design","regression_testing"],
    ["threat findings","required controls","release blockers"]],
  ["deployment", "Release verified work with environment checks, backups and rollback.", 3,
    ["deployment","rollback_design","regression_testing","documentation"],
    ["release plan","preflight","backup","verification","rollback procedure"]],
  ["documentation", "Keep architecture, runbooks, APIs, campaigns and changes understandable and durable.", 2,
    ["documentation","ecosystem_awareness","dependency_mapping"],
    ["architecture update","runbook","changelog","decision record"]],
  ["optimization", "Continuously improve conversion, ROI and network performance from measured results.", 2,
    ["experimentation","customer_behavior","merchant_roi","opportunity_scoring","retention","acquisition"],
    ["optimization backlog","experiment","expected impact","success threshold"]],
  ["opportunity_discovery", "Find monetizable opportunities that have not yet been explicitly requested.", 2,
    ["opportunity_scoring","revenue_awareness","local_market_intelligence","customer_behavior","network_effects","revenue_leak_detection"],
    ["ranked opportunity queue","evidence","revenue hypothesis","recommended owner agent"]],
  ["business_twin", "Maintain a decision-ready intelligence model for each merchant.", 2,
    ["merchant_intelligence","customer_behavior","campaign_generation","revenue_leak_detection","merchant_roi","segmentation"],
    ["merchant profile","current lifecycle state","opportunities","next-best campaign"]],
  ["simulation", "Model campaign economics before launch under conservative, expected and aggressive scenarios.", 2,
    ["simulation","merchant_roi","reward_design","attribution","campaign_generation"],
    ["scenario model","break-even point","risk flags","recommended parameters"]]
];

export function createSpecialistAgents() {
  return DEFINITIONS.map(([name, mission, maxAutonomy, skills, outputs]) => new PlanningAgent({ name, mission, maxAutonomy, skills, outputs }));
}

const ROUTES = [
  [/treasure|hunt|northeast ohio/i, ["treasure_hunt","campaign_architect","sales","revenue_architect","analytics_roi"]],
  [/363|radio cigar|foundation/i, ["three63","campaign_architect","mystery_engine","revenue_architect","demo_factory"]],
  [/mystery|drop|gamif/i, ["mystery_engine","rewards_intelligence","campaign_architect","analytics_roi"]],
  [/revenue leak|recover|churn|dormant/i, ["revenue_leak","business_twin","campaign_architect","analytics_roi"]],
  [/campaign/i, ["campaign_architect","simulation","analytics_roi","revenue_architect"]],
  [/sell|sales|subscription|prospect|close/i, ["sales","local_intelligence","demo_factory","revenue_architect"]],
  [/network|directory|cross.business/i, ["network","growth","analytics_roi"]],
  [/stripe|webhook|integration|wordpress|mcp/i, ["integration","architecture_guardian","security","qa_adversarial"]],
  [/frontend|ui|ux|card|dashboard/i, ["frontend_product","architecture_guardian","qa_adversarial"]],
  [/laravel|backend|database|api|code|build|implement/i, ["architecture_guardian","backend_engineer","security","qa_adversarial","deployment"]]
];

export function routeObjective(objective = "") {
  const selected = new Set(["monetization_controller"]);
  for (const [pattern, agents] of ROUTES) {
    if (pattern.test(objective)) agents.forEach(agent => selected.add(agent));
  }
  if (selected.size === 1) ["architecture_guardian","revenue_architect","opportunity_discovery"].forEach(a => selected.add(a));
  return [...selected];
}
