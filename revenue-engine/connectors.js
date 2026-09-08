import { ensure } from './errors.js';
import { businessRecord, choice, fields } from './validation.js';

/** Provider boundary: consume authorized exports, retaining evidence metadata. No network access. */
export class ImportConnector {
  constructor(id, section = null) { this.id = id; this.section = section; this.mode = 'import'; }
  importRecords(payload) {
    fields(payload, ['records'], ['records']);
    ensure(Array.isArray(payload.records) && payload.records.length > 0 && payload.records.length <= 100, 'VALIDATION', 'Import requires 1–100 records');
    return payload.records.map(record => {
      const valid = businessRecord(record);
      if (this.section) ensure(valid[this.section], 'VALIDATION', `Provider requires ${this.section} evidence`);
      return valid;
    });
  }
}

/** Deliberately simulates downstream actions. Cannot call Places Rewards, send messages or charge money. */
export class SandboxExecutionConnector {
  constructor() { this.id = 'sandbox'; this.mode = 'sandbox'; }
  async execute({ executionId, kind, payload, signal }) {
    choice(kind, ['recovery', 'outreach'], 'execution kind');
    signal?.throwIfAborted();
    return { executionId, provider: this.id, mode: this.mode, status: 'simulated', externalMutation: false, reference: `sandbox:${executionId}`, action: kind === 'recovery' ? payload.route : 'outreach-draft' };
  }
}

export class ConnectorRegistry {
  constructor({ execution = new SandboxExecutionConnector() } = {}) {
    ensure(execution?.mode === 'sandbox' && typeof execution.execute === 'function', 'UNSAFE_CONNECTOR', 'Only sandbox execution connectors are supported', 503);
    this.execution = execution;
    this.imports = new Map([
      new ImportConnector('merchant-export'), new ImportConnector('crm-export', 'crm'),
      new ImportConnector('call-export', 'calls'), new ImportConnector('public-profile-export', 'publicProfile'),
      new ImportConnector('website-export', 'website'),
    ].map(connector => [connector.id, connector]));
  }
  importRecords(id, payload) {
    ensure(this.imports.has(id), 'NOT_FOUND', 'Provider not found', 404);
    return this.imports.get(id).importRecords(payload);
  }
  describe() {
    return { imports: [...this.imports.values()].map(({ id, mode, section }) => ({ id, mode, section })), execution: { id: this.execution.id, mode: 'sandbox', externalMutation: false } };
  }
}
