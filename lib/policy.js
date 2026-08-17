export function evaluateAction(risk, autonomyLevel) {
  if (risk === "read") return { allowed: true, requiresApproval: false, reason: "Read-only action allowed automatically." };
  if (risk === "safe_write") {
    if (autonomyLevel >= 3) return { allowed: true, requiresApproval: false, reason: "Safe write allowed at autonomy level 3 or higher." };
    return { allowed: false, requiresApproval: true, reason: "Safe write requires autonomy level 3 or explicit approval." };
  }
  if (risk === "protected_write") return { allowed: false, requiresApproval: true, reason: "Production-impacting writes require explicit approval." };
  return { allowed: false, requiresApproval: true, reason: "Destructive actions always require explicit approval." };
}
