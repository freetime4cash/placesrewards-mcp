export type LeakCategory =
  | "missed_call"
  | "lead_followup"
  | "retention"
  | "reactivation"
  | "referral"
  | "reputation"
  | "local_visibility"
  | "conversion";

export interface BusinessSignal {
  source: string;
  key: string;
  value: number | string | boolean;
  observedAt: string;
  evidence?: string;
}

export interface BusinessProfile {
  id: string;
  name: string;
  industry?: string;
  monthlyRevenue?: number;
  averageTicket?: number;
  signals: BusinessSignal[];
}

export interface RevenueLeak {
  id: string;
  businessId: string;
  category: LeakCategory;
  title: string;
  evidence: string[];
  confidence: number;
  estimatedMonthlyLoss: number;
  estimatedAnnualLoss: number;
  recommendedFix: string;
  placesRewardsModule?: string;
}

export interface RevenueOpportunityReport {
  businessId: string;
  generatedAt: string;
  totalEstimatedMonthlyLoss: number;
  totalEstimatedAnnualLoss: number;
  leaks: RevenueLeak[];
}
