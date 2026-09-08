/** Public JSON DTOs. ISO timestamps are strings; all monetary values are modeled USD. */
export type Stage = 'discovered' | 'diagnosed' | 'quantified' | 'prescribed' | 'demonstrated' | 'closed' | 'recovering' | 'measured';
export type Tier = 'disabled' | 'growth' | 'pro' | 'enterprise';
export type QueueStatus = 'ready' | 'claimed' | 'snoozed' | 'done';
export type ActionStatus = 'draft' | 'pending_approval' | 'approved' | 'executing' | 'uncertain' | 'simulated';
export interface Signal { source: string; key: string; value: number | string | boolean; observedAt: string; evidence?: string }
export interface EvidenceQuality { score: number; freshness: number; coverage: number; traceability: number }
export interface Business { id: string; name: string; industry?: string; averageTicket?: number; monthlyRevenue?: number; signals: Signal[] }
export interface Leak { id: string; businessId: string; category: string; title: string; evidence: string[]; confidence: number; estimatedMonthlyLoss: number; estimatedAnnualLoss: number; recommendedFix: string; recoveryRoute: string }
export interface Diagnosis { businessId: string; generatedAt: string; totalEstimatedMonthlyLoss: number; totalEstimatedAnnualLoss: number; leaks: Leak[] }
export interface Quantification { modeledMonthlyLoss: number; modeledAnnualLoss: number; confidenceWeightedMonthlyOpportunity: number; confidenceWeightedAnnualOpportunity: number; leakCount: number; opportunityScore: number }
export interface PlanAction { priority: number; leakId: string; category: string; estimatedMonthlyRecovery: number; action: string; route: string; approvalRequired: true }
export interface Approval { id: string; actorId: string; at: string; expiresAt: string; reason: string; payloadHash: string; connectorId: string; mode: 'sandbox' }
export interface ExecutionReceipt { executionId: string; provider: string; mode: 'sandbox'; status: 'simulated'; externalMutation: false; reference: string }
export interface Execution { id: string; at: string; actorId: string; approvalId: string; finishedAt?: string; receipt?: ExecutionReceipt | null; error?: { code: string; message: string } | null }
export interface ApprovalEvent { decision: 'approve' | 'revoke'; actorId: string; at: string; reason: string; approval: Approval | null }
export interface ReconciledExecution extends Execution { reconciledBy: string; reconciledAt: string; outcome: 'not_executed' | 'simulated'; evidence: string }
export interface GatedAction { status: ActionStatus; approved?: boolean; approval?: Approval | null; approvalHistory?: ApprovalEvent[]; execution?: Execution; executions?: ReconciledExecution[] }
export interface MissedCall {
  eventId: string; caller: string; businessNumber: string; occurredAt: string; evidence: string;
  smsPermission: { allowed: true; evidence: string }; sendAfter: string; expiresAt: string; bookingUrl: string | null; message: string;
  disposition: 'pending' | 'replied' | 'callback_requested' | 'booked' | 'opted_out';
  responses: Array<{ eventId: string; text: string; outcome: 'replied' | 'callback_requested' | 'booked' | 'opted_out'; at: string; actorId: string; mode: 'sandbox' }>;
}
export interface RecoveryAction extends PlanAction, GatedAction { missedCall?: MissedCall; createdAt?: string; createdBy?: string }
export interface Outreach extends GatedAction { id: string; channel: 'email'; recipient: string; subject: string; body: string; approvalRequired: true; createdBy: string; createdAt: string; outcomes?: Array<{ outcome: 'replied' | 'meeting_booked' | 'declined' | 'no_response'; notes: string; followUpAt: string | null; actorId: string; at: string; mode: 'sandbox' }> }
export interface ExecutiveReport { business: Pick<Business, 'id' | 'name' | 'industry'>; headline: string; summary: { monthlyOpportunity: number; annualOpportunity: number; leakCount: number; score: number }; findings: Array<{ title: string; evidence: string[]; confidence: number; monthlyLoss: number; annualLoss: number; recommendedFix: string }>; generatedAt: string; disclaimer: string }
export interface Demonstration { headline: string; executiveReport: ExecutiveReport | null; proofPoints: Array<{ category: string; evidence: string[]; confidence: number; monthlyLoss: number; recommendedFix: string }>; disclaimer: string }
export interface Close { status: 'won' | 'lost' | 'deferred'; agreedMonthlyFee: number; recoverySharePercent: number; notes: string; closedAt: string }
export interface Recovery { startedAt: string; approvedActionCount: number; actions: RecoveryAction[] }
export interface Measurement { measuredAt: string; currentReport: Diagnosis; comparison: { previous: number; current: number; delta: number; improved: boolean; recoveredEstimate: number; regressedEstimate: number }; recoveredMonthlyEstimate: number; recoveredAnnualizedEstimate: number; claimStatus: 'modeled-improvement' | 'no-modeled-improvement'; evidence: Signal[]; executionMode: 'sandbox'; verifiedRecoveredRevenue: null }
export interface Opportunity {
  id: string; tenantId: string; version: number; business: Business; source: string; tier: Tier;
  entitlement: { tier: Tier; enabled: boolean; capabilities: { diagnostics: boolean; automatedDiscovery: boolean; continuousMonitoring: boolean; recoveryAutomation: boolean; advancedReporting: boolean } };
  stage: Stage; score: number; createdAt: string; updatedAt: string;
  history: Array<{ stage: Stage; at: string; reason?: string }>;
  qualification: 'priority' | 'qualified' | 'nurture' | 'low-priority'; evidenceQuality: EvidenceQuality; discoveryReport: Diagnosis;
  report: Diagnosis | null; quantified: Quantification | null; recoveryPlan: PlanAction[] | null;
  demonstration: Demonstration | null; close: Close | null; recovery: Recovery | null; measurement: Measurement | null;
  queue: { status: QueueStatus; owner: string | null; followUpAt: string | null; leaseUntil: string | null }; outreach: Outreach[];
}
export interface Report {
  contractVersion: '1.0'; opportunityId: string; version: number; business: Pick<Business, 'id' | 'name'>; stage: Stage;
  evidence: { signals: Signal[]; quality: EvidenceQuality }; diagnosis: Diagnosis | null; quantification: Quantification | null;
  prescription: PlanAction[] | null; demonstration: Demonstration | null; close: Close | null; recovery: Recovery | null; measurement: Measurement | null;
  currency: 'USD'; claimStatus: 'modeled-opportunity' | 'modeled-improvement' | 'no-modeled-improvement'; verifiedRecoveredRevenue: null; disclaimer: string;
}
export interface Dashboard {
  contractVersion: '1.0'; currency: 'USD'; total: number; stages: Record<Stage, number>; closeOutcomes: Record<'won' | 'lost' | 'deferred', number>;
  modeledMonthlyOpportunity: number; confidenceWeightedMonthlyOpportunity: number; modeledMonthlyImprovement: number;
  verifiedRecoveredRevenue: null; pendingApproval: number; uncertainExecutions: number; executionMode: 'sandbox'; disclaimer: string;
  missedCalls: { total: number; pendingApproval: number; scheduled: number; expired: number; simulated: number; replied: number; callbackRequested: number; booked: number; optedOut: number; uncertain: number };
}
export interface Page<T> { items: T[]; total: number; offset: number; limit: number; nextOffset: number | null }
export interface AuditEvent { id: string; at: string; tenantId: string; actorId: string; operation: string; opportunityId: string; version?: number; provider?: string; executionId?: string }
export type Response<T> = { contractVersion: '1.0'; requestId: string; ok: true; data: T } | { contractVersion: '1.0'; requestId: string; ok: false; error: { code: string; message: string } };
export interface VersionCommand { version: number }
export type ApprovalCommand = VersionCommand & ({ decision: 'approve'; reason: string; expiresAt: string } | { decision: 'revoke'; reason: string });
export interface ReconcileCommand extends VersionCommand { outcome: 'not_executed' | 'simulated'; evidence: string }
export interface CallbackCall {
  callId: string; assistantId: string; phoneNumberId: string; endedAt: string;
  phone: string | null; name: string | null; summary: string; endedReason: string; callbackRequested: boolean; evidence: string;
}
export interface ManualCallback {
  id: string; tenantId: string; opportunityId: string; version: number; source: 'vapi'; call: CallbackCall;
  status: 'pending_approval' | 'approved' | 'completed' | 'cancelled'; dueAt: string; createdAt: string; updatedAt: string;
  approval: { id: string; actorId: string; at: string; expiresAt: string; reason: string; payloadHash: string } | null;
  approvalHistory: Array<NonNullable<ManualCallback['approval']>>;
  attempts: Array<{ outcome: 'reached' | 'booked' | 'no_answer' | 'do_not_call'; notes: string; actorId: string; at: string; approvalId: string; reportedByHuman: true }>;
  history: Array<{ event: string; at: string; actorId: string; reason?: string }>;
  contactMode: 'manual'; verifiedRecoveredRevenue: null;
}
export interface CallbackSummary {
  total: number; pendingApproval: number; approved: number; completed: number; cancelled: number; needsNumber: number;
  due: number; reportedBookings: number; verifiedRecoveredRevenue: null; contactMode: 'manual';
}
export interface VapiBinding { tenantId: string; opportunityId: string; assistantId: string; phoneNumberId: string }
