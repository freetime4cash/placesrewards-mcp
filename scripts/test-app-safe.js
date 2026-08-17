import { runSafeTests } from "../lib/code-tools.js";

const result = await runSafeTests();

const safe = {
  passed: result.passed,
  checks: result.checks.map((check) => ({
    command: check.command,
    exitCode: check.exitCode,
    stderr: check.stderr,
    stdout:
      check.command.includes("config:show")
        ? "[REDACTED: config output intentionally suppressed]"
        : check.stdout
  }))
};

console.log(JSON.stringify(safe, null, 2));

if (!safe.passed) {
  process.exit(1);
}
