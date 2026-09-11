import type { BusinessProfile, BusinessSignal } from "./types.js";

export interface RawBusinessRecord {
  id: string;
  name: string;
  industry?: string;
  monthlyRevenue?: number;
  averageTicket?: number;
  metrics?: Record<string, unknown>;
  source?: string;
  observedAt?: string;
}

export interface SignalAdapter {
  name: string;
  supports(record: RawBusinessRecord): boolean;
  toSignals(record: RawBusinessRecord): BusinessSignal[];
}

export class GenericMetricsAdapter implements SignalAdapter {
  name = "generic-metrics";

  supports(record: RawBusinessRecord): boolean {
    return Boolean(record.metrics && Object.keys(record.metrics).length > 0);
  }

  toSignals(record: RawBusinessRecord): BusinessSignal[] {
    const observedAt = record.observedAt ?? new Date().toISOString();
    const source = record.source ?? this.name;

    return Object.entries(record.metrics ?? {}).flatMap(([key, value]) => {
      if (!["number", "string", "boolean"].includes(typeof value)) return [];
      return [{ source, key, value: value as number | string | boolean, observedAt }];
    });
  }
}

export class RevenueSignalIngestor {
  constructor(private readonly adapters: SignalAdapter[] = [new GenericMetricsAdapter()]) {}

  normalize(record: RawBusinessRecord): BusinessProfile {
    const signals = this.adapters
      .filter((adapter) => adapter.supports(record))
      .flatMap((adapter) => adapter.toSignals(record));

    return {
      id: record.id,
      name: record.name,
      ...(record.industry ? { industry: record.industry } : {}),
      ...(record.monthlyRevenue !== undefined ? { monthlyRevenue: record.monthlyRevenue } : {}),
      ...(record.averageTicket !== undefined ? { averageTicket: record.averageTicket } : {}),
      signals: this.dedupe(signals),
    };
  }

  private dedupe(signals: BusinessSignal[]): BusinessSignal[] {
    const latest = new Map<string, BusinessSignal>();
    for (const signal of signals) {
      const key = `${signal.source}:${signal.key}`;
      const current = latest.get(key);
      if (!current || signal.observedAt >= current.observedAt) latest.set(key, signal);
    }
    return [...latest.values()];
  }
}
