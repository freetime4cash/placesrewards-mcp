import { createPlacesRewardsApi } from "./api.js";
import { inspectLaravel, runSafeTests, createPatchProposal, applyPatchProposal, rollbackBackup } from "./code-tools.js";
import { routeObjective } from "./specialist-agents.js";

export class CommandAgent {
  name = "command";
  maxAutonomy = 2;
  async run(context) {
    const specialists = routeObjective(context.objective);
    const specialistTasks = specialists.map((agent, index) => ({
      agent,
      objective: context.objective,
      priority: 95 - index,
      autonomyLevel: 2,
      input: context.input
    }));

    return {
      ok: true,
      summary: `PR Orchestrator analyzed and routed: ${context.objective}`,
      data: {
        routing: specialists,
        tasks: [
          ...specialistTasks,
          { agent: "build", objective: "Inspect the live Laravel Places Rewards application and Agent API, identify concrete capability gaps relevant to the objective, and prepare only safe development actions.", priority: 80, autonomyLevel: 2, input: context.input },
          { agent: "test", objective: "Run non-destructive Laravel diagnostics and verify the live Places Rewards Agent API before any production-impacting work.", priority: 79, autonomyLevel: 2 },
          { agent: "documentation", objective: `Maintain durable architecture/runbook context for: ${context.objective}`, priority: 40, autonomyLevel: 2 }
        ]
      }
    };
  }
}

export class BuildAgent {
  name = "build";
  maxAutonomy = 3;
  async run(context) {
    const api = createPlacesRewardsApi();
    const input = context.input ?? {};

    if (input.operation === "prepare_patch") {
      const [laravel, health, tools, analytics] = await Promise.all([
        inspectLaravel(), api.health(), api.tools(), api.analyticsOverview()
      ]);
      const proposal = await createPatchProposal({ jobId: context.jobId, files: input.files, rationale: input.rationale });
      return {
        ok: true,
        summary: "Build Agent prepared an isolated patch proposal. Production files were not changed.",
        data: {
          laravel, liveApi: { health, tools, analytics }, proposal,
          actions: [{ name: "prepare_patch_workspace", risk: "safe_write", details: { execute: true, proposalId: proposal.id, productionChanged: false } }]
        }
      };
    }

    if (["apply_patch", "execute_approved_patch"].includes(input.operation)) {
      const approval = context.approval;
      if (!approval?.approvedAt || approval.source !== "explicit_job_approval") {
        return {
          ok: true,
          requiresApproval: true,
          summary: "Build Agent is blocked from applying the prepared patch until this exact job passes the explicit approval transition.",
          data: {
            proposalId: input.proposalId,
            actions: [{ name: "apply_patch_to_production", risk: "protected_write", details: { proposalId: input.proposalId, execute: false, approvalRequired: true } }]
          }
        };
      }

      const applied = await applyPatchProposal(input.proposalId);
      const tests = await runSafeTests();

      if (!tests.passed) {
        await rollbackBackup(applied.backupDir);
        return {
          ok: false,
          summary: "Approved patch failed validation and was rolled back automatically.",
          data: { applied, tests, rollback: { performed: true, backupDir: applied.backupDir } }
        };
      }

      return {
        ok: true,
        summary: "Explicitly approved patch was applied and passed validation.",
        data: {
          applied,
          tests,
          approval: { approvedAt: approval.approvedAt, source: approval.source },
          actions: [{ name: "production_patch_applied_and_verified", risk: "read", details: { proposalId: input.proposalId, backupDir: applied.backupDir } }]
        }
      };
    }

    const [laravel, health, tools, analytics] = await Promise.all([
      inspectLaravel(), api.health(), api.tools(), api.analyticsOverview()
    ]);
    return {
      ok: true,
      summary: `Build Agent inspected the live Laravel app and Agent API for: ${context.objective}`,
      data: {
        laravel, liveApi: { health, tools, analytics },
        assessment: "Inspection completed. No production files were modified.",
        actions: [{ name: "inspect_laravel_and_live_api", risk: "read", details: { execute: true } }]
      }
    };
  }
}

export class TestAgent {
  name = "test";
  maxAutonomy = 2;
  async run(context) {
    const api = createPlacesRewardsApi();
    let apiHealth = null, apiError = null;
    try { apiHealth = await api.health(); }
    catch (e) { apiError = e instanceof Error ? e.message : String(e); }

    const laravelTests = await runSafeTests();
    const passed = Boolean(apiHealth) && laravelTests.passed;

    return {
      ok: passed,
      summary: passed ? `Test Agent verified Laravel diagnostics and live Agent API: ${context.objective}` : `Test Agent detected a Laravel or live API issue: ${context.objective}`,
      data: {
        liveApi: { passed: Boolean(apiHealth), health: apiHealth, error: apiError },
        laravel: laravelTests,
        passed,
        actions: [{ name: "run_non_destructive_laravel_tests", risk: "read", details: { execute: true } }]
      }
    };
  }
}

export class DemoAgent {
  name = "demo";
  maxAutonomy = 3;
  async run(context) {
    return {
      ok: true,
      summary: `Demo Agent prepared a controlled write request for: ${context.objective}`,
      data: {
        actions: [{
          name: "demo_write_gate",
          risk: "safe_write",
          details: { execute: false, purpose: "Real demo writes remain disabled until explicitly enabled." }
        }]
      }
    };
  }
}
