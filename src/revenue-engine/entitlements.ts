export type RevenueEngineTier = "disabled" | "growth" | "pro" | "enterprise";

export interface RevenueEngineEntitlement {
  tier: RevenueEngineTier;
  enabled: boolean;
  capabilities: {
    diagnostics: boolean;
    automatedDiscovery: boolean;
    continuousMonitoring: boolean;
    recoveryAutomation: boolean;
    advancedReporting: boolean;
  };
}

export const REVENUE_ENGINE_ENTITLEMENTS: Record<RevenueEngineTier, RevenueEngineEntitlement> = {
  disabled: {
    tier: "disabled",
    enabled: false,
    capabilities: {
      diagnostics: false,
      automatedDiscovery: false,
      continuousMonitoring: false,
      recoveryAutomation: false,
      advancedReporting: false,
    },
  },
  growth: {
    tier: "growth",
    enabled: true,
    capabilities: {
      diagnostics: true,
      automatedDiscovery: false,
      continuousMonitoring: false,
      recoveryAutomation: false,
      advancedReporting: false,
    },
  },
  pro: {
    tier: "pro",
    enabled: true,
    capabilities: {
      diagnostics: true,
      automatedDiscovery: true,
      continuousMonitoring: false,
      recoveryAutomation: false,
      advancedReporting: true,
    },
  },
  enterprise: {
    tier: "enterprise",
    enabled: true,
    capabilities: {
      diagnostics: true,
      automatedDiscovery: true,
      continuousMonitoring: true,
      recoveryAutomation: true,
      advancedReporting: true,
    },
  },
};

export function requireRevenueEngineCapability(
  entitlement: RevenueEngineEntitlement,
  capability: keyof RevenueEngineEntitlement["capabilities"],
): void {
  if (!entitlement.enabled || !entitlement.capabilities[capability]) {
    throw new Error(`Revenue Engine capability '${capability}' is not enabled for tier '${entitlement.tier}'.`);
  }
}
