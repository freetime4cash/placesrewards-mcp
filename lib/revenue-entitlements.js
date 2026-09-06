export const REVENUE_ENGINE_ENTITLEMENTS = Object.freeze({
  disabled: Object.freeze({
    tier: "disabled",
    enabled: false,
    capabilities: Object.freeze({ diagnostics: false, automatedDiscovery: false, continuousMonitoring: false, recoveryAutomation: false, advancedReporting: false })
  }),
  growth: Object.freeze({
    tier: "growth",
    enabled: true,
    capabilities: Object.freeze({ diagnostics: true, automatedDiscovery: false, continuousMonitoring: false, recoveryAutomation: false, advancedReporting: false })
  }),
  pro: Object.freeze({
    tier: "pro",
    enabled: true,
    capabilities: Object.freeze({ diagnostics: true, automatedDiscovery: true, continuousMonitoring: false, recoveryAutomation: false, advancedReporting: true })
  }),
  enterprise: Object.freeze({
    tier: "enterprise",
    enabled: true,
    capabilities: Object.freeze({ diagnostics: true, automatedDiscovery: true, continuousMonitoring: true, recoveryAutomation: true, advancedReporting: true })
  })
});

export function entitlementForTier(tier = "disabled") {
  return REVENUE_ENGINE_ENTITLEMENTS[tier] ?? REVENUE_ENGINE_ENTITLEMENTS.disabled;
}

export function requireRevenueCapability(entitlement, capability) {
  if (!entitlement?.enabled || entitlement.capabilities?.[capability] !== true) {
    throw new Error(`Revenue Engine capability '${capability}' is not enabled for tier '${entitlement?.tier ?? "unknown"}'.`);
  }
  return true;
}
