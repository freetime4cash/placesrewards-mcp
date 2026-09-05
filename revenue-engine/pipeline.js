import { RevenueSignalAggregator } from './providers.js';
import { evidenceQuality, confidenceMultiplier } from './evidence.js';
import { diagnose, opportunityScore, entitlementFor, requireCapability } from './index.js';

const money = n => Math.round((Number(n) || 0) * 100) / 100;

function businessFromEnriched(record) {
  return {
    id:String(record.id),
    name:record.name,
    industry:record.industry,
    monthlyRevenue:record.monthlyRevenue,
    averageTicket:record.averageTicket,
    signals:record.signals || [],
  };
}

export class RevenueDiscoveryPipeline {
  constructor({ tier='pro', aggregator=new RevenueSignalAggregator() }={}) {
    this.entitlement = entitlementFor(tier);
    this.aggregator = aggregator;
  }

  analyze(record) {
    requireCapability(this.entitlement,'automatedDiscovery');
    const enriched = this.aggregator.enrich(record);
    const business = businessFromEnriched(enriched);
    const report = diagnose(business);
    const quality = evidenceQuality(business.signals);
    const multiplier = confidenceMultiplier(business.signals);
    const adjustedLeaks = report.leaks.map(leak => ({
      ...leak,
      confidence:money(Math.min(0.99, leak.confidence * multiplier)),
    }));
    const adjustedReport = {...report, leaks:adjustedLeaks};
    const rawScore = opportunityScore(adjustedReport);
    const evidenceBoost = Math.min(10, quality.score/10);
    const score = money(Math.min(100, rawScore + evidenceBoost));
    return {
      business,
      evidenceQuality:quality,
      report:adjustedReport,
      opportunityScore:score,
      qualification:this.qualify(score, adjustedReport.totalEstimatedMonthlyLoss, quality.score),
    };
  }

  rank(records=[]) {
    return records
      .map(record=>this.analyze(record))
      .filter(x=>x.report.leaks.length > 0)
      .sort((a,b)=>b.opportunityScore-a.opportunityScore);
  }

  qualify(score, monthlyLoss, evidenceScore) {
    if (score >= 75 && monthlyLoss >= 2500 && evidenceScore >= 55) return 'priority';
    if (score >= 55 && monthlyLoss >= 750) return 'qualified';
    if (score >= 35) return 'nurture';
    return 'low-priority';
  }
}
