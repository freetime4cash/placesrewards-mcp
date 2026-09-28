import type { BusinessProfile } from "./types.js";
import { RevenueEngine } from "./engine.js";

export interface DiscoveryCandidate {
  business: BusinessProfile;
  opportunityScore: number;
  estimatedMonthlyLoss: number;
  estimatedAnnualLoss: number;
  leakCount: number;
  topLeak?: string;
}

export class RevenueDiscoveryEngine {
  constructor(private readonly engine = new RevenueEngine()) {}

  rank(businesses: BusinessProfile[]): DiscoveryCandidate[] {
    return businesses
      .map((business) => {
        const report = this.engine.diagnose(business);
        const confidence = report.leaks.length
          ? report.leaks.reduce((sum, leak) => sum + leak.confidence, 0) / report.leaks.length
          : 0;
        const lossScore = Math.min(60, Math.log10(report.totalEstimatedMonthlyLoss + 1) * 15);
        const breadthScore = Math.min(20, report.leaks.length * 5);
        const confidenceScore = confidence * 20;

        return {
          business,
          opportunityScore: Math.round((lossScore + breadthScore + confidenceScore) * 100) / 100,
          estimatedMonthlyLoss: report.totalEstimatedMonthlyLoss,
          estimatedAnnualLoss: report.totalEstimatedAnnualLoss,
          leakCount: report.leaks.length,
          ...(report.leaks[0] ? { topLeak: report.leaks[0].title } : {}),
        };
      })
      .filter((candidate) => candidate.leakCount > 0)
      .sort((a, b) => b.opportunityScore - a.opportunityScore);
  }
}
