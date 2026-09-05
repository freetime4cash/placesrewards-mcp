const TIERS = {
  disabled: { diagnostics:false, automatedDiscovery:false, continuousMonitoring:false, recoveryAutomation:false, advancedReporting:false },
  growth: { diagnostics:true, automatedDiscovery:false, continuousMonitoring:false, recoveryAutomation:false, advancedReporting:false },
  pro: { diagnostics:true, automatedDiscovery:true, continuousMonitoring:false, recoveryAutomation:false, advancedReporting:true },
  enterprise: { diagnostics:true, automatedDiscovery:true, continuousMonitoring:true, recoveryAutomation:true, advancedReporting:true },
};

const money = n => Math.round((Number(n) || 0) * 100) / 100;
const signal = (business, key) => business.signals?.find(s => s.key === key)?.value;
const num = (business, key) => typeof signal(business, key) === 'number' ? signal(business, key) : undefined;

export function entitlementFor(tier='disabled') {
  if (!TIERS[tier]) throw new Error(`Unknown Revenue Engine tier: ${tier}`);
  return { tier, enabled:tier !== 'disabled', capabilities:{...TIERS[tier]} };
}

export function requireCapability(entitlement, capability) {
  if (!entitlement?.enabled || !entitlement.capabilities?.[capability]) {
    throw new Error(`Revenue Engine capability '${capability}' is not enabled for tier '${entitlement?.tier ?? 'unknown'}'.`);
  }
}

export function normalizeBusiness(record) {
  const observedAt = record.observedAt || new Date().toISOString();
  const source = record.source || 'generic';
  const metrics = record.metrics || {};
  const signals = Object.entries(metrics)
    .filter(([,v]) => ['number','string','boolean'].includes(typeof v))
    .map(([key,value]) => ({ source, key, value, observedAt }));
  return {
    id:String(record.id), name:record.name, industry:record.industry,
    monthlyRevenue:record.monthlyRevenue, averageTicket:record.averageTicket, signals
  };
}

function leak(business, category, title, loss, evidence, recommendedFix, confidence, recoveryRoute) {
  return {
    id:`${business.id}:${category}`, businessId:business.id, category, title,
    evidence, confidence, estimatedMonthlyLoss:money(loss), estimatedAnnualLoss:money(loss*12),
    recommendedFix, recoveryRoute
  };
}

export function diagnose(business) {
  const leaks = [];
  const ticket = business.averageTicket ?? num(business,'average_ticket') ?? 0;
  const missedCalls = num(business,'missed_calls_monthly');
  const callConversion = num(business,'call_conversion_rate') ?? 0.35;
  if (missedCalls > 0 && ticket > 0) leaks.push(leak(business,'missed_call','Missed calls are leaking revenue',missedCalls*callConversion*ticket,[`${missedCalls} missed calls/month`,`Average ticket: $${money(ticket)}`],'Deploy missed-call capture, instant text-back and booking follow-up.',0.90,'communications'));

  const dormant = num(business,'dormant_customers');
  const reactivateRate = num(business,'reactivation_rate') ?? 0.08;
  if (dormant > 0 && ticket > 0) leaks.push(leak(business,'reactivation','Dormant customers represent recoverable revenue',dormant*reactivateRate*ticket,[`${dormant} dormant customers`,`Modeled reactivation rate: ${money(reactivateRate*100)}%`],'Launch segmented win-back campaigns and offers.',0.82,'places-rewards:offers'));

  const repeatGap = num(business,'repeat_purchase_gap_monthly');
  if (repeatGap > 0 && ticket > 0) leaks.push(leak(business,'retention','Repeat-purchase gap is suppressing lifetime value',repeatGap*ticket,[`${repeatGap} missing repeat transactions/month`],'Deploy loyalty/stamp incentives and lifecycle retention offers.',0.78,'places-rewards:loyalty'));

  const uncontactedLeads = num(business,'uncontacted_leads_monthly');
  const leadCloseRate = num(business,'lead_close_rate') ?? 0.20;
  if (uncontactedLeads > 0 && ticket > 0) leaks.push(leak(business,'lead_followup','Uncontacted leads are leaking conversion revenue',uncontactedLeads*leadCloseRate*ticket,[`${uncontactedLeads} uncontacted leads/month`,`Modeled close rate: ${money(leadCloseRate*100)}%`],'Deploy rapid lead response, qualification and follow-up sequences.',0.86,'communications'));

  const reviewRating = num(business,'review_rating');
  const reviewCount = num(business,'review_count');
  if (reviewRating !== undefined && reviewRating < 4.2 && ticket > 0) {
    const modeledLostTx = Math.max(1, (4.2-reviewRating) * Math.max(5,(reviewCount||10)*0.1));
    leaks.push(leak(business,'reputation','Reputation weakness may be suppressing conversion',modeledLostTx*ticket,[`Observed rating: ${reviewRating}`,`Observed review count: ${reviewCount ?? 'unknown'}`],'Deploy review-request automation and service-recovery workflows.',0.62,'places-rewards:reviews'));
  }

  leaks.sort((a,b)=>b.estimatedMonthlyLoss-a.estimatedMonthlyLoss);
  const monthly = money(leaks.reduce((s,l)=>s+l.estimatedMonthlyLoss,0));
  return { businessId:business.id, generatedAt:new Date().toISOString(), totalEstimatedMonthlyLoss:monthly, totalEstimatedAnnualLoss:money(monthly*12), leaks };
}

export function opportunityScore(report) {
  if (!report.leaks.length) return 0;
  const confidence = report.leaks.reduce((s,l)=>s+l.confidence,0)/report.leaks.length;
  const lossScore = Math.min(60, Math.log10(report.totalEstimatedMonthlyLoss+1)*15);
  const breadthScore = Math.min(20, report.leaks.length*5);
  return money(lossScore+breadthScore+confidence*20);
}

export function rankBusinesses(records, entitlement=entitlementFor('pro')) {
  requireCapability(entitlement,'automatedDiscovery');
  return records.map(normalizeBusiness).map(business=>{
    const report=diagnose(business);
    return {business,report,opportunityScore:opportunityScore(report)};
  }).filter(x=>x.report.leaks.length).sort((a,b)=>b.opportunityScore-a.opportunityScore);
}

export function buildRecoveryPlan(report, entitlement=entitlementFor('growth')) {
  requireCapability(entitlement,'diagnostics');
  return report.leaks.map((l,index)=>({priority:index+1, leakId:l.id, category:l.category, estimatedMonthlyRecovery:l.estimatedMonthlyLoss, action:l.recommendedFix, route:l.recoveryRoute, approvalRequired:true}));
}

export function buildExecutiveReport(business, report, entitlement=entitlementFor('pro')) {
  requireCapability(entitlement,'advancedReporting');
  return {
    business:{id:business.id,name:business.name,industry:business.industry},
    headline:`Estimated annual revenue opportunity: $${report.totalEstimatedAnnualLoss.toLocaleString()}`,
    summary:{monthlyOpportunity:report.totalEstimatedMonthlyLoss,annualOpportunity:report.totalEstimatedAnnualLoss,leakCount:report.leaks.length,score:opportunityScore(report)},
    findings:report.leaks.map(l=>({title:l.title,evidence:l.evidence,confidence:l.confidence,monthlyLoss:l.estimatedMonthlyLoss,annualLoss:l.estimatedAnnualLoss,recommendedFix:l.recommendedFix})),
    generatedAt:new Date().toISOString(), disclaimer:'Estimates are modeled opportunities based on available signals and should be validated against merchant data.'
  };
}

export function compareSnapshots(previousReport,currentReport,entitlement=entitlementFor('enterprise')) {
  requireCapability(entitlement,'continuousMonitoring');
  const delta=money(currentReport.totalEstimatedMonthlyLoss-previousReport.totalEstimatedMonthlyLoss);
  return {previous:previousReport.totalEstimatedMonthlyLoss,current:currentReport.totalEstimatedMonthlyLoss,delta,improved:delta<0,recoveredEstimate:delta<0?Math.abs(delta):0,regressedEstimate:delta>0?delta:0};
}

export class RevenueEngineService {
  constructor({tier='disabled'}={}) { this.entitlement=entitlementFor(tier); }
  diagnose(record) { requireCapability(this.entitlement,'diagnostics'); const business=normalizeBusiness(record); return {business,report:diagnose(business)}; }
  discover(records) { return rankBusinesses(records,this.entitlement); }
  report(record) { const {business,report}=this.diagnose(record); return buildExecutiveReport(business,report,this.entitlement); }
  recoveryPlan(record) { const {report}=this.diagnose(record); return buildRecoveryPlan(report,this.entitlement); }
  monitor(previousRecord,currentRecord) { requireCapability(this.entitlement,'continuousMonitoring'); const previous=diagnose(normalizeBusiness(previousRecord)); const current=diagnose(normalizeBusiness(currentRecord)); return compareSnapshots(previous,current,this.entitlement); }
}
