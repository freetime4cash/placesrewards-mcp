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
  },
  reputation: {
    offer: "Reputation & Review Growth Pilot",
    primaryModule: "reviews / reputation",
    demo: "Show a completed visit flowing into private feedback, a compliant review request and measurable review-response activity.",
    campaign: "Trigger post-visit feedback and review requests, route service recovery appropriately and track review-request completion and review velocity.",
    kpi: "review-request completion and recent review velocity"
  },
  booking_friction: {
    offer: "Booking Conversion Pilot",
    primaryModule: "lead / booking follow-up",
    demo: "Show a customer moving from inquiry to a simpler booking path, with follow-up for incomplete booking attempts.",
    campaign: "Measure inquiry-to-booking completion, reduce avoidable steps and follow up on incomplete booking journeys.",
    kpi: "inquiry-to-booking completion rate"
  },
  loyalty_gap: {
    offer: "Repeat-Visit Revenue Engine",
    primaryModule: "loyalty/stamp-cards",
    demo: "Show a first visit becoming a loyalty enrollment, a next-visit incentive and an attributable repeat visit.",
    campaign: "Introduce a simple repeat-visit loyalty mechanic, measure enrollment and compare return behavior with the pre-pilot baseline.",
    kpi: "loyalty enrollment and repeat-visit rate"
  },
  referral_gap: {
    offer: "Referral Growth Loop",
    primaryModule: "referrals",
    demo: "Show a customer receiving a shareable referral, a referred customer converting and the referral being attributed end to end.",
    campaign: "Launch a trackable referral incentive with clear eligibility, attribution and conversion measurement.",
    kpi: "qualified referrals and referred-customer conversion"
  },
  event_retention: {
    offer: "Post-Event Retention Loop",
    primaryModule: "campaigns / loyalty / referrals",
    demo: "Show an event attendee opting into a post-event journey, receiving a return incentive and generating an attributable return visit or referral.",
    campaign: "Capture event participants into a permissioned post-event sequence with return-visit and referral mechanics and cohort measurement.",
    kpi: "post-event return visits and referrals"
  },
  reactivation_signal: {
    offer: "Reactivation Validation Sprint",
    primaryModule: "offers/campaigns",
    demo: "Show how a merchant would validate a dormant-customer baseline before launching a segmented win-back journey.",
    campaign: "Validate dormant-customer volume first, then run a small segmented win-back pilot with attribution.",
    kpi: "validated reactivation rate"
  },
  followup_friction: {
    offer: "Lead Follow-Up Validation Sprint",
    primaryModule: "follow-up automation",
    demo: "Show an inquiry entering immediate follow-up, escalating until booked, closed or disqualified, with every outcome tracked.",
    campaign: "Establish the current inquiry response baseline, then test faster multi-step follow-up and measure contact and booking rates.",
    kpi: "lead contact and booking rate"
  }
});

function contextOpportunity(context) {
  const input = context?.input ?? {};
  const opportunity = input.opportunity ?? input.revenueOpportunity ?? null;
  return opportunity && typeof opportunity === "object" ? opportunity : null;
}

function base(opportunity) {
  const business = opportunity.business ?? {};
  const report = opportunity.report ?? {};
  const leaks = Array.isArray(report.leaks) ? report.leaks : [];
  const primary = leaks[0] ?? null;
  const monetaryClaimAvailable = report.monetaryClaimAvailable !== false && Number(report.totalEstimatedMonthlyLoss ?? 0) > 0;
  const playbook = PLAYBOOKS[primary?.category] ?? {
    offer: monetaryClaimAvailable ? "Revenue Recovery Pilot" : "Measured Growth Validation Pilot",
    primaryModule: primary?.placesRewardsModule ?? "Places Rewards",
    demo: "Demonstrate the highest-confidence observed opportunity and the measurable improvement loop.",
    campaign: primary?.recommendedFix ?? "Run a measurable pilot against the highest-confidence opportunity.",
    kpi: monetaryClaimAvailable ? "measured recovered revenue" : "measured improvement against baseline"
  };
  return {
    business,
    report,
    leaks,
    primary,
    playbook,
    monetaryClaimAvailable,
    businessName: business.name ?? report.businessName ?? report.businessId ?? "merchant",
    score: money(opportunity.score ?? opportunity.opportunityScore ?? 0),
    evidenceScore: money(opportunity.evidenceQuality?.score ?? 0),
    modeledMonthly: monetaryClaimAvailable ? money(report.totalEstimatedMonthlyLoss ?? 0) : 0,
    modeledAnnual: monetaryClaimAvailable ? money(report.totalEstimatedAnnualLoss ?? 0) : 0
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
    ),
    publicEvidence: Array.isArray(opportunity.publicEvidence) ? opportunity.publicEvidence : []
  };
}

function audienceFor(category) {
  if (category === "reactivation" || category === "reactivation_signal") return "dormant or lapsed customers after the baseline is validated";
  if (category === "retention" || category === "loyalty_gap") return "recent customers and first-time customers eligible for a repeat-visit journey";
  if (category === "referral_gap") return "satisfied existing customers eligible to refer friends or family";
  if (category === "event_retention") return "event or promotion participants who permissioned follow-up";
  if (category === "booking_friction" || category === "lead_followup" || category === "followup_friction") return "prospects or inquiries entering the booking/follow-up journey";
  if (category === "reputation") return "recent customers eligible for feedback and review requests";
  return "customers or leads affected by the identified opportunity";
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
    const economicGuardrail = x.monetaryClaimAvailable
      ? `Price the initial engagement so the merchant can plausibly see value well below the modeled $${x.modeledMonthly.toFixed(2)} monthly opportunity; do not represent the model as guaranteed savings.`
      : "Do not price against claimed recovered revenue until merchant-owned baseline data establishes a measurable economic opportunity.";
    const positioning = x.monetaryClaimAvailable
      ? "Start with one measurable revenue leak, prove movement against a baseline, then expand into recurring Places Rewards / Revenue Engine capabilities."
      : "Start with one evidence-backed public opportunity, validate the merchant baseline, then use a narrow measurable pilot to prove whether recurring Places Rewards value exists.";

    return {
      ok: true,
      summary: `Prepared a ${qualification} conversion path for ${x.businessName}.`,
      data: {
        agent: this.name,
        qualification,
        opportunityScore: x.score,
        evidenceQuality: x.evidenceScore,
        offer: {
          name: opportunity.recommendedEntryOffer ?? x.playbook.offer,
          positioning,
          commercialModel: "setup or pilot fee + recurring subscription; use configured public pricing when available",
          economicGuardrail
        },
        openingProof: {
          monetaryClaimAvailable: x.monetaryClaimAvailable,
          modeledMonthlyOpportunity: x.modeledMonthly,
          modeledAnnualOpportunity: x.modeledAnnual,
          highestPriorityLeak: x.primary?.title ?? null,
          disclaimer: x.monetaryClaimAvailable
            ? "Opportunity values are modeled estimates until validated against merchant-owned source data."
            : "No monetary loss is claimed from public evidence. The observed facts support a sales hypothesis that must be validated with merchant-owned baseline data."
        },
        demoChoice: x.playbook.demo,
        objections: [
          { objection: "We already have marketing.", response: "This is a measurable customer-lifecycle or conversion workflow, not a generic advertising package." },
          { objection: "How do I know it will work?", response: "Use a baseline, a narrow pilot and attribution. The purpose is to prove or disprove the opportunity before expanding." },
          { objection: "I do not want another complicated system.", response: "Lead with one workflow tied to one measurable outcome; introduce broader ecosystem features only after value is demonstrated." }
        ],
        closePath: [
          "Validate the observed evidence and current baseline with the merchant.",
          `Demonstrate the ${opportunity.recommendedEntryOffer ?? x.playbook.offer} workflow against the highest-priority opportunity.`,
          "Agree on one KPI and a short measurement window.",
          "Convert the validated pilot into the appropriate recurring subscription tier only if the economics support it."
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
  mission = "Design the minimum measurable recovery or growth campaign for an evidence-backed opportunity.";
  maxAutonomy = 2;
  skills = ["campaign_generation", "segmentation", "reward_design", "merchant_roi", "attribution", "simulation", "retention", "referrals"];

  async run(context) {
    const opportunity = contextOpportunity(context);
    if (!opportunity) return { ok: true, summary: "Campaign architect requires a qualified revenue opportunity.", data: { agent: this.name, ready: false, actions: [{ name: "campaign_input_requirements", risk: "read" }] } };
    const x = base(opportunity);
    const threshold = x.monetaryClaimAvailable
      ? {
          type: "validation_target_not_guarantee",
          recoveredValueTarget: money(x.modeledMonthly * 0.15),
          note: "Use 15% of modeled monthly opportunity as an initial validation target only; recalibrate after baseline data is confirmed."
        }
      : {
          type: "baseline_improvement_target",
          recoveredValueTarget: null,
          note: "Set a numeric improvement target only after merchant-owned baseline data has been validated."
        };
    return {
      ok: true,
      summary: `Prepared a measurable pilot campaign for ${x.businessName}.`,
      data: {
        agent: this.name,
        campaign: {
          name: `${x.businessName} - ${opportunity.recommendedEntryOffer ?? x.playbook.offer}`,
          objective: x.primary?.recommendedFix ?? x.playbook.campaign,
          audience: audienceFor(x.primary?.category),
          mechanic: x.playbook.campaign,
          primaryModule: x.primary?.placesRewardsModule ?? x.playbook.primaryModule,
          baseline: {
            monetaryClaimAvailable: x.monetaryClaimAvailable,
            modeledMonthlyOpportunity: x.modeledMonthly,
            evidenceQuality: x.evidenceScore,
            requiredBeforeLaunch: "capture the current baseline for the primary KPI and validate the evidence with the merchant"
          },
          kpis: [x.playbook.kpi, "conversion or behavior change versus baseline"],
          measurementWindowDays: 30,
          successThreshold: threshold,
          attribution: "Every pilot action must retain the originating cohort/event so outcomes can be compared with the pre-pilot baseline.",
          stopConditions: ["economics become negative", "merchant revokes authorization", "tracking is insufficient to support attribution", "customer experience or compliance risk appears"]
        },
        rollout: ["validate baseline", "small cohort", "measure", "adjust", "expand only if economics remain positive"],
        actions: [{ name: "design_recovery_campaign", risk: "read", details: { execute: true } }]
      }
    };
  }
}

export class DemoFactoryExecutionAgent {
  name = "demo_factory";
  mission = "Create a safe personalized demonstration tied to an evidence-backed merchant outcome.";
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
          title: `${x.businessName}: ${opportunity.recommendedEntryOffer ?? x.playbook.offer}`,
          story: x.playbook.demo,
          proofSequence: [
            "Show the observed evidence and explicitly label hypotheses or modeled assumptions.",
            "Show the customer/lead entering the proposed workflow.",
            "Show the Places Rewards automation or campaign response.",
            "Show how the relevant conversion/behavior event would be attributed.",
            "Show the before/after KPI view and the decision rule for continuing or stopping the pilot."
          ],
          requiredArtifacts: ["one baseline card", "one customer journey", "one pilot mechanic", "one attribution result", "one KPI explanation"],
          moduleMap: [...new Set(x.leaks.map(leak => leak.placesRewardsModule).filter(Boolean).concat(x.playbook.primaryModule))],
          claimsPolicy: "Never present public-fit hypotheses or modeled opportunity as guaranteed revenue. Separate observed facts, hypotheses, modeled estimates and measured results.",
          productionCreationAllowed: false
        },
        conversionNarrative: x.monetaryClaimAvailable
          ? "The diagnosis identifies a measurable leak; Places Rewards demonstrates the recovery workflow; measurement determines whether the workflow should become a recurring subscription."
          : "Public evidence identifies a testable opportunity; the merchant baseline validates whether the gap is real; Places Rewards demonstrates a pilot and measurement determines whether recurring value exists.",
        nextBestAction: "build_or_select_nonproduction_demo_assets_then_attach_to_sales_packet",
        actions: [{ name: "prepare_demo_specification", risk: "read", details: { execute: true } }]
      }
    };
  }
}
