const dayMs = 86400000;

export function evidenceQuality(signals = []) {
  if (!signals.length) return { score: 0, freshness: 0, coverage: 0, traceability: 0 };
  const now = Date.now();
  const freshness = signals.reduce((sum, signal) => {
    const parsed = Date.parse(signal?.observedAt ?? "");
    const ageDays = Number.isFinite(parsed) ? Math.max(0, (now - parsed) / dayMs) : 90;
    return sum + Math.max(0, 1 - Math.min(ageDays, 90) / 90);
  }, 0) / signals.length;
  const coverage = Math.min(1, new Set(signals.map(signal => signal?.key).filter(Boolean)).size / 8);
  const traceability = signals.filter(signal => Boolean(signal?.evidence || signal?.source)).length / signals.length;
  const score = Math.round((freshness * 0.35 + coverage * 0.35 + traceability * 0.30) * 10000) / 100;
  return {
    score,
    freshness: Math.round(freshness * 10000) / 100,
    coverage: Math.round(coverage * 10000) / 100,
    traceability: Math.round(traceability * 10000) / 100
  };
}

export function confidenceMultiplier(signals = []) {
  const quality = evidenceQuality(signals).score / 100;
  return 0.55 + quality * 0.45;
}

export function mergeRevenueSignals(...groups) {
  const latest = new Map();
  for (const signal of groups.flat().filter(Boolean)) {
    if (!signal?.key) continue;
    const prior = latest.get(signal.key);
    const observedAt = signal.observedAt ?? new Date().toISOString();
    if (!prior || String(observedAt) >= String(prior.observedAt ?? "")) latest.set(signal.key, { ...signal, observedAt });
  }
  return [...latest.values()];
}

export function signalFromMetric(key, value, { source = "unknown", observedAt = new Date().toISOString(), evidence = null } = {}) {
  if (!["number", "boolean", "string"].includes(typeof value)) return null;
  return { key, value, source, observedAt, ...(evidence ? { evidence } : {}) };
}
