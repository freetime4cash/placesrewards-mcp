const money = value => Math.round((Number(value) || 0) * 100) / 100;

const PLAYBOOKS = Object.freeze({
  missed_call: {
    offer: "Missed-Call Revenue Recovery Pilot",
    primaryModule: "automated follow-up / recovery",
    demo: "Show an unanswered-call-to-text-back-to-booking journey with attribution back to the original missed call.",
    campaign: "Immediate text-back, qualification, booking link, escalation and closed-loop outcome tracking.",
    kpi: "recovered appointments or sales from previously missed calls"
  },
  reactivation: {
    offer: "Dormant Customer Reactivation Sprint",
    primaryModule: "offers/campaigns",
    demo: "Show a dormant-customer segment receiving a targeted win-back offer, returning, and being measured as reactivated revenue.",
    campaign: "Segment dormant customers, deploy time-boxed win-back offers, follow up automatically and measure reactivation by cohort.",
    kpi: "reactivated customers and recovered revenue"
  },
  retention: {
    offer: "Repeat-Visit Revenue Engine",
    primaryModule: "loyalty/stamp-cards",
    demo: "Show a first visit converting into a loyalty enrollment, next-visit incentive and repeat-purchase attribution.",
    campaign: "Enroll customers at purchase, trigger next-visit incentives, reinforce with loyalty milestones and measure repeat frequency.",
    kpi: "repeat-purchase rate and visit frequency"
  },
  lead_followup: {
    offer: "Lead Recovery Sprint",
    primaryModule: "follow-up automation",
    demo: "Show a new lead entering an immediate follow-up sequence, escalating until booked, closed or disqualified.",
    campaign: "Immediate multi-step follow-up with clear stop conditions, booking conversion and attribution.",
    kpi: "lead contact rate, booked rate and recovered sales"
  },
  no_show: {
    offer: "Appointment Recovery Sprint",
    primaryModule: "reminders / recovery automation",
    demo: "Show confirmation, reminder, missed-appointment recovery and one-click rescheduling with recovered-value tracking.",
    campaign: "Confirm appointments, send reminders, recover missed appointments and measure rescheduled revenue.",
    kpi: "no-show recovery rate and recovered appointment revenue"
  }
});

function contextOpportunity(context) {
  const input = context?.input ?? {};
  const opportunity = input.opportunity ?? input.revenueOpportunity ?? null;
  if (!opportunity || typeof opportunity !== "object") return null;
  return opportunity;
}

function base(opportunity) {
  const business = opportunity.business ?? {};
  const report = opportunity.report ?? {};
  const leaks = Array.isArray(report.leaks) ? report.leaks : [];
  const primary = leaks[0] ?? null;
  const playbook = PLAYBOOKS[primary?.category] ?? {
    offer: "Revenue Recovery Pilot",
    primaryModule: primary?.placesRewardsModule ?? "Places Rewards",
    demo: "Demonstrate the highest-confidence diagnosed revenue leak and the measurable recovery loop.",
    campaign: primary?.recommendedFix ?? "Run a measurable recovery campaign against the highest-confidence leak.",
    kpi: "measured recovered revenue"
  };
  return {
    business,
    report,
    leaks,
    primary,
    playbook,
    businessName: business.name ?? report.businessName ?? report.businessId ?? "merchant",
    score: money(opportunity.score ?? opportunity.opportunityScore ?? 0),
    evidenceScore: money(opportunity.evidenceQuality?.score ?? 0),
    modeledMonthly: money(report.totalEstimatedMonthlyLoss ?? 0),
    modeledAnnual: money(report.totalEstimatedAnnualLoss ?? 0)
  };
}

function evidenceBreakdown(opportunity) {
  const signals = Array.isArray(opportunity.business?.signals) ? opportunity.business.signals : [];
  return {
    observedSignals: signals.map(signal => ({
      key: signal.key ?? null,
      value: signal.value ?? null,
      source: signal.source ?? null,
      observedAt: signal.observedAt ?? null,
      evidence: signal.evidence ?? null,
      derivedFrom: signal.derivedFrom ?? null
    })),
    modeledAssumptions: (opportunity.report?.leaks ?? []).flatMap(leak =>
      Array.isArray(leak.evidence)
        ? leak.evidence.filter(item => /modeled|estimated/i.test(String(item))).map(item => ({ category: leak.category, assumption: item }))
        : []
    )
  };
}

export class SalesExecutionAgent {
  name = "sales";
  mission = "Convert qualified businesses using the shortest evidence-based path to paid revenue.";
  maxAutonomy = 2;
  skills = ["sales_conversion", "merchant_intelligence", "demo_engineering", "revenue_modeling", "merchant_roi"];

  async run(context) {
    const opportunity = contextOpportunity(context);
    if (!opportunity) return { ok: true, summary: "Sales agent requires a qualified revenue opportunity.", data: { agent: this.name, ready: false, actions: [{ name: "sales_input_requirements", risk: "read" }] } };
    const x = base(opportunity);
    const qualification = x.score >= 45 && x.evidenceScore >= 45 ? "qualified" : "needs_more_evidence";
    const economicGuardrail = x.modeledMonthly > 0
      ? `Price the initial engagement so the merchant can plausibly see value well below the modeled $${x.modeledMonthly.toFixed(2)} monthly opportunity; do not represent the model as guaranteed savings.`
      : "Do not price against modeled recovery until a measurable baseline exists.";

    return {
      ok: true,
      summary: `Prepared a ${qualification} conversion path for ${x.businessName}.`,
      data: {
        agent: this.name,
        qualification,
        opportunityScore: x.score,
        evidenceQuality: x.evidenceScore,
        offer: {
          name: x.playbook.offer,
          positioning: `Start with one measurable leak, prove movement against a baseline, then expand into recurring Places Rewards / Revenue Engine capabilities.`,
          commercialModel: "setup or pilot fee + recurring subscription; use configured public pricing when available",
          economicGuardrail
        },
        openingProof: {
          modeledMonthlyOpportunity: x.modeledMonthly,
          modeledAnnualOpportunity: x.modeledAnnual,
          highestPriorityLeak: x.primary?.title ?? null,
          disclaimer: "All opportunity values are modeled estimates until validated against merchant-owned source data."
        },
        demoChoice: x.playbook.demo,
        objections: [
          { objection: "We already have marketing.", response: "This is positioned as measurable revenue recovery from an identified operational leak, not generic advertising." },
          { objection: "How do I know it will work?", response: "Use a baseline, a narrow pilot and attribution. The goal is to measure improvement before expanding." },
          { objection: "I do not want another complicated system.", response: "Lead with the single recovery workflow tied to the diagnosed leak; introduce broader ecosystem features only after value is demonstrated." }
        ],
        closePath: [
          "Validate the observed evidence and baseline with the merchant.",
          `Demonstrate the ${x.playbook.offer} workflow against the highest-priority leak.`,
          "Agree on one KPI and a short measurement window.",
          "Convert the validated pilot into the appropriate recurring subscription tier."
        ],
        nextBestAction: qualification === "qualified" ? "prepare_outreach_draft_and_personalized_demo_packet" : "collect_additional_evidence_before_outreach",
        evidence: evidenceBreakdown(opportunity),
        actions: [{ name: "prepare_conversion_packet", risk: "read", details: { execute: true } }]
      }
    };
  }
}

export class CampaignExecutionAgent {
  name = "campaign_architect";
  mission = "Design the minimum measurable recovery campaign for a diagnosed revenue leak.";
  maxAutonomy = 2;
  skills = ["campaign_generation", "segmentation", "reward_design", "merchant_roi", "attribution", "simulation", "retention", "referrals"];

  async run(context) {
    const opportunity = contextOpportunity(context);
    if (!opportunity) return { ok: true, summary: "Campaign architect requires a qualified revenue opportunity.", data: { agent: this.name, ready: false, actions: [{ name: "campaign_input_requirements", risk: "read" }] } };
    const x = base(opportunity);
    const targetValue = money(x.modeledMonthly * 0.15);
    return {
      ok: true,
      summary: `Prepared a measurable recovery campaign for ${x.businessName}.`,
      data: {
        agent: this.name,
        campaign: {
          name: `${x.businessName} - ${x.playbook.offer}`,
          objective: x.primary?.recommendedFix ?? x.playbook.campaign,
          audience: x.primary?.category === "reactivation" ? "dormant or lapsed customers" : x.primary?.category === "retention" ? "recent customers at risk of not returning" : "customers or leads affected by the diagnosed leak",
          mechanic: x.playbook.campaign,
          primaryModule: x.primary?.placesRewardsModule ?? x.playbook.primaryModule,
          baseline: {
            modeledMonthlyOpportunity: x.modeledMonthly,
            evidenceQuality: x.evidenceScore,
            requiredBeforeLaunch: "capture the current leak volume and current conversion/recovery rate"
          },
          kpis: [x.playbook.kpi, "attributed recovered revenue", "conversion or recovery rate"],
          measurementWindowDays: 30,
          successThreshold: {
            type: "validation_target_not_guarantee",
            recoveredValueTarget: targetValue,
            note: "Use 15% of modeled monthly opportunity as an initial validation target only; recalibrate after baseline data is confirmed."
          },
          attribution: "Every recovery action must retain the originating cohort/event so recovered outcomes can be compared with the pre-campaign baseline.",
          stopConditions: ["economics become negative", "merchant revokes authorization", "tracking is insufficient to support attribution", "customer experience or compliance risk appears"]
        },
        rollout: ["baseline", "small cohort", "measure", "adjust", "expand only if economics remain positive"],
        actions: [{ name: "design_recovery_campaign", risk: "read", details: { execute: true } }]
      }
    };
  }
}

export class DemoFactoryExecutionAgent {
  name = "demo_factory";
  mission = "Create a safe personalized demonstration tied to a diagnosed merchant outcome.";
  maxAutonomy = 2;
  skills = ["demo_engineering", "campaign_generation", "mystery_mechanics", "sales_conversion", "merchant_roi"];

  async run(context) {
    const opportunity = contextOpportunity(context);
    if (!opportunity) return { ok: true, summary: "Demo factory requires a qualified revenue opportunity.", data: { agent: this.name, ready: false, actions: [{ name: "demo_input_requirements", risk: "read" }] } };
    const x = base(opportunity);
    return {
      ok: true,
      summary: `Prepared a personalized demo specification for ${x.businessName}.`,
      data: {
        agent: this.name,
        demo: {
          title: `${x.businessName}: ${x.playbook.offer}`,
          story: x.playbook.demo,
          proofSequence: [
            "Show the observed evidence and explicitly label modeled assumptions.",
            "Show the customer/lead entering the recovery workflow.",
            "Show the Places Rewards automation or campaign response.",
            "Show the attributable conversion/recovery event.",
            "Show the before/after KPI view and the path to recurring optimization."
          ],
          requiredArtifacts: ["one baseline card", "one customer journey", "one recovery mechanic", "one attribution result", "one ROI explanation"],
          moduleMap: [...new Set(x.leaks.map(leak => leak.placesRewardsModule).filter(Boolean).concat(x.playbook.primaryModule))],
          claimsPolicy: "Never present modeled opportunity as guaranteed revenue. Separate observed metrics, assumptions, projected opportunity and measured results.",
          productionCreationAllowed: false
        },
        conversionNarrative: `The diagnosis identifies a specific leak; Places Rewards demonstrates the recovery workflow; measurement proves whether the workflow should become a recurring subscription.`,
        nextBestAction: "build_or_select_nonproduction_demo_assets_then_attach_to_sales_packet",
        actions: [{ name: "prepare_demo_specification", risk: "read", details: { execute: true } }]
      }
    };
  }
}
