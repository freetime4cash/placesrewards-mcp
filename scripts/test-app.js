import { runSafeTests } from "../lib/code-tools.js";
const result = await runSafeTests();
console.log(JSON.stringify(result, null, 2));
if (!result.passed) process.exit(1);
