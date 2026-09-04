import type { BusinessProfile, RevenueLeak, RevenueOpportunityReport } from "./types.js";

const num = (business: BusinessProfile, key: string): number | undefined => {
  const signal = business.signals.find((item) => item.key === key);
  return typeof signal?.value === "number" ? signal.value : undefined;
};

const clampConfidence = (value: number) => Math.max(0, Math.min(1, value));

export class RevenueEngine {
  diagnose(business: BusinessProfile): RevenueOpportunityReport {
    const leaks: RevenueLeak[] = [];
    const ticket = business.averageTicket ?? num(business, "average_ticket") ?? 0;

    const missedCalls = num(business, "missed_calls_monthly");
    const callConversion = num(business, "call_conversion_rate") ?? 0.35;
    if (missedCalls !== undefined && missedCalls > 0 && ticket > 0) {
      const loss = missedCalls * callConversion * ticket;
      leaks.push(this.leak(business.id, "missed_call", "Missed calls are leaking revenue", loss,
        [`${missedCalls} missed calls/month`, `Average ticket: $${ticket.toFixed(2)}`],
        "Deploy missed-call capture, immediate text-back, qualification and booking follow-up.", undefined, 0.9));
    }

    const dormant = num(business, "dormant_customers");
    const reactivateRate = num(business, "reactivation_rate") ?? 0.08;
    if (dormant !== undefined && dormant > 0 && ticket > 0) {
      const loss = dormant * reactivateRate * ticket;
      leaks.push(this.leak(business.id, "reactivation", "Dormant customers represent recoverable revenue", loss,
        [`${dormant} dormant customers`, `Modeled recoverable rate: ${(reactivateRate * 100).toFixed(1)}%`],
        "Launch segmented win-back offers and automated reactivation sequences.", "offers/campaigns", 0.82));
    }

    const repeatGap = num(business, "repeat_purchase_gap_monthly");
    if (repeatGap !== undefined && repeatGap > 0 && ticket > 0) {
      const loss = repeatGap * ticket;
      leaks.push(this.leak(business.id, "retention", "Repeat-purchase gap is suppressing customer lifetime value", loss,
        [`Estimated ${repeatGap} missing repeat transactions/month`],
        "Deploy loyalty/stamp incentives with lifecycle-triggered retention offers.", "loyalty/stamp-cards", 0.78));
    }

    const monthly = leaks.reduce((sum, leak) => sum + leak.estimatedMonthlyLoss, 0);
    return {
      businessId: business.id,
      generatedAt: new Date().toISOString(),
      totalEstimatedMonthlyLoss: this.money(monthly),
      totalEstimatedAnnualLoss: this.money(monthly * 12),
      leaks: leaks.sort((a, b) => b.estimatedMonthlyLoss - a.estimatedMonthlyLoss),
    };
  }

  private leak(businessId: string, category: RevenueLeak["category"], title: string, monthlyLoss: number,
    evidence: string[], recommendedFix: string, placesRewardsModule: string | undefined, confidence: number): RevenueLeak {
    return {
      id: `${businessId}:${category}`,
      businessId,
      category,
      title,
      evidence,
      confidence: clampConfidence(confidence),
      estimatedMonthlyLoss: this.money(monthlyLoss),
      estimatedAnnualLoss: this.money(monthlyLoss * 12),
      recommendedFix,
      ...(placesRewardsModule ? { placesRewardsModule } : {}),
    };
  }

  private money(value: number): number {
    return Math.round(value * 100) / 100;
  }
}
