const money = value => Math.round((Number(value) || 0) * 100) / 100;
const clamp01 = value => Math.max(0, Math.min(1, Number(value) || 0));

function signalValue(business, key) {
  const direct = business?.metrics?.[key];
  if (typeof direct === "number" && Number.isFinite(direct)) return direct;
  const signal = Array.isArray(business?.signals) ? business.signals.find(item => item?.key === key) : null;
  return typeof signal?.value === "number" && Number.isFinite(signal.value) ? signal.value : undefined;
}

function makeLeak({ businessId, category, title, monthlyLoss, evidence, recommendedFix, placesRewardsModule, confidence }) {
  return {
    id: `${businessId}:${category}`,
    businessId,
    category,
    title,
    evidence,
    confidence: clamp01(confidence),
    estimatedMonthlyLoss: money(monthlyLoss),
    estimatedAnnualLoss: money(monthlyLoss * 12),
    recommendedFix,
    ...(placesRewardsModule ? { placesRewardsModule } : {})
  };
}

export function diagnoseRevenueLeaks(business) {
  if (!business || typeof business !== "object") throw new Error("business profile is required");
  const businessId = String(business.id ?? business.name ?? "unknown-business");
  const ticket = Number(business.averageTicket ?? signalValue(business, "average_ticket") ?? 0);
  const leaks = [];

  const missedCalls = signalValue(business, "missed_calls_monthly");
  const callConversion = signalValue(business, "call_conversion_rate") ?? 0.35;
  if (missedCalls > 0 && ticket > 0) leaks.push(makeLeak({
    businessId, category: "missed_call", title: "Missed calls are leaking revenue",
    monthlyLoss: missedCalls * callConversion * ticket,
    evidence: [`${missedCalls} missed calls/month`, `Average ticket: $${money(ticket).toFixed(2)}`, `Modeled conversion: ${(callConversion * 100).toFixed(1)}%`],
    recommendedFix: "Deploy missed-call capture, immediate text-back, qualification and booking follow-up.", confidence: 0.90
  }));

  const dormant = signalValue(business, "dormant_customers");
  const reactivationRate = signalValue(business, "reactivation_rate") ?? 0.08;
  if (dormant > 0 && ticket > 0) leaks.push(makeLeak({
    businessId, category: "reactivation", title: "Dormant customers represent recoverable revenue",
    monthlyLoss: dormant * reactivationRate * ticket,
    evidence: [`${dormant} dormant customers`, `Modeled recoverable rate: ${(reactivationRate * 100).toFixed(1)}%`],
    recommendedFix: "Launch segmented win-back offers and automated reactivation sequences.", placesRewardsModule: "offers/campaigns", confidence: 0.82
  }));

  const repeatGap = signalValue(business, "repeat_purchase_gap_monthly");
  if (repeatGap > 0 && ticket > 0) leaks.push(makeLeak({
    businessId, category: "retention", title: "Repeat-purchase gap is suppressing customer lifetime value",
    monthlyLoss: repeatGap * ticket,
    evidence: [`Estimated ${repeatGap} missing repeat transactions/month`],
    recommendedFix: "Deploy loyalty/stamp incentives with lifecycle-triggered retention offers.", placesRewardsModule: "loyalty/stamp-cards", confidence: 0.78
  }));

  const uncontactedLeads = signalValue(business, "uncontacted_leads_monthly");
  const leadConversion = signalValue(business, "lead_conversion_rate") ?? 0.20;
  if (uncontactedLeads > 0 && ticket > 0) leaks.push(makeLeak({
    businessId, category: "lead_followup", title: "Uncontacted leads are decaying before conversion",
    monthlyLoss: uncontactedLeads * leadConversion * ticket,
    evidence: [`${uncontactedLeads} uncontacted leads/month`, `Modeled conversion: ${(leadConversion * 100).toFixed(1)}%`],
    recommendedFix: "Trigger immediate multi-channel lead follow-up with escalation until booked, closed, or disqualified.", confidence: 0.80
  }));

  const noShows = signalValue(business, "no_shows_monthly");
  const noShowRecovery = signalValue(business, "no_show_recovery_rate") ?? 0.45;
  if (noShows > 0 && ticket > 0) leaks.push(makeLeak({
    businessId, category: "no_show", title: "No-shows are creating recoverable appointment revenue loss",
    monthlyLoss: noShows * noShowRecovery * ticket,
    evidence: [`${noShows} no-shows/month`, `Modeled recoverable rate: ${(noShowRecovery * 100).toFixed(1)}%`],
    recommendedFix: "Use confirmation, reminder, rescheduling and recovery automation around appointments.", confidence: 0.76
  }));

  leaks.sort((a, b) => b.estimatedMonthlyLoss - a.estimatedMonthlyLoss);
  const monthly = leaks.reduce((sum, item) => sum + item.estimatedMonthlyLoss, 0);
  const averageConfidence = leaks.length ? leaks.reduce((sum, item) => sum + item.confidence, 0) / leaks.length : 0;
  return {
    businessId,
    businessName: business.name ?? null,
    generatedAt: new Date().toISOString(),
    totalEstimatedMonthlyLoss: money(monthly),
    totalEstimatedAnnualLoss: money(monthly * 12),
    averageConfidence: money(averageConfidence),
    leaks
  };
}

export function scoreRevenueOpportunity(report) {
  const lossScore = Math.min(60, Math.log10((report?.totalEstimatedMonthlyLoss ?? 0) + 1) * 15);
  const breadthScore = Math.min(20, (report?.leaks?.length ?? 0) * 5);
  const confidenceScore = clamp01(report?.averageConfidence ?? 0) * 20;
  return money(lossScore + breadthScore + confidenceScore);
}

export function rankRevenueOpportunities(businesses = []) {
  return businesses.map(business => {
    const report = diagnoseRevenueLeaks(business);
    return {
      business,
      report,
      opportunityScore: scoreRevenueOpportunity(report),
      estimatedMonthlyLoss: report.totalEstimatedMonthlyLoss,
      estimatedAnnualLoss: report.totalEstimatedAnnualLoss,
      leakCount: report.leaks.length,
      topLeak: report.leaks[0]?.title ?? null
    };
  }).filter(item => item.leakCount > 0).sort((a, b) => b.opportunityScore - a.opportunityScore);
}

export function buildRecoveryPlan(report) {
  return {
    businessId: report.businessId,
    objective: `Recover measurable revenue for ${report.businessName ?? report.businessId}`,
    estimatedMonthlyOpportunity: report.totalEstimatedMonthlyLoss,
    actions: report.leaks.map((item, index) => ({
      priority: index + 1,
      category: item.category,
      estimatedMonthlyValue: item.estimatedMonthlyLoss,
      recommendedFix: item.recommendedFix,
      placesRewardsModule: item.placesRewardsModule ?? null,
      confidence: item.confidence,
      risk: "safe_write",
      executionState: "planned"
    })),
    measurement: {
      baselineRequired: true,
      compareAfterDays: 30,
      metrics: ["recovered_revenue", "conversion_rate", "repeat_purchase_rate", "reactivated_customers", "appointments_recovered"]
    }
  };
}
