#!/usr/bin/env bash
set -u

AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
APP="/home/placevle/app.placesrewards.com"
LOG="$AGENT/cron-worker.log"
SCHEMA_OUT="$AGENT/results/campaigns/live-agent-tools-export.txt"
SCHEMA_JSON="$AGENT/results/campaigns/live-agent-tools-generic.json"
ROUTES_JSON="$AGENT/results/campaigns/live-agent-routes.json"
EXPORT_HELP="$AGENT/results/campaigns/agent-export-tools-help.txt"
PHP_DIAG="$AGENT/results/campaigns/php-cli-diagnostic.txt"
ROLE_PROBE="$AGENT/results/campaigns/363-role-and-club-probe.json"
TOOL_FILE="$APP/app/Services/Agent/AgentToolService.php"

cd "$AGENT" || exit 1

DESIRED="* * * * * /bin/bash $AGENT/run-worker-cron.sh"
CURRENT="$(crontab -l 2>/dev/null || true)"
CLEANED="$(printf '%s\n' "$CURRENT" | grep -v '/placesrewards-agent-server/run-worker-cron\.sh' || true)"
{ printf '%s\n' "$CLEANED"; printf '%s\n' "$DESIRED"; } | awk 'NF && !seen[$0]++' | crontab -

PHPCLI=""
for CANDIDATE in /opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/php /usr/bin/php /usr/local/bin/ea-php84 /opt/alt/php84/usr/bin/php; do
  if [ -x "$CANDIDATE" ] && "$CANDIDATE" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then PHPCLI="$CANDIDATE"; break; fi
done
mkdir -p "$AGENT/bin" "$AGENT/data/backups"
if [ -n "$PHPCLI" ]; then ln -sf "$PHPCLI" "$AGENT/bin/php"; export PATH="$AGENT/bin:$PATH"; fi

{
  echo "===== $(date -Iseconds) ====="
  git fetch origin server-runtime
  git reset --hard origin/server-runtime
  mkdir -p "$AGENT/bin"
  if [ -n "$PHPCLI" ]; then ln -sf "$PHPCLI" "$AGENT/bin/php"; export PATH="$AGENT/bin:$PATH"; fi

  "$NODE" worker.js
  "$NODE" scripts/github-campaign-worker.mjs

  # Probe whether the admin key can access partner-scoped routes and list existing clubs for every live partner.
  "$NODE" - "$ROLE_PROBE" <<'NODE'
import { promises as fs } from 'node:fs';
const out = process.argv[2];
const base = (process.env.PLACESREWARDS_API_URL || 'https://app.placesrewards.com/api/agent/v1').replace(/\/+$/,'');
const key = process.env.PLACESREWARDS_AGENT_KEY || '';
const headers = { Accept:'application/json', 'X-Agent-Key':key };
const partnerIds = [
  '019dc1d1-772a-7024-b79e-75e5413ca154',
  '019dbfc9-ddf9-7136-951a-124574cf7b3e',
  '019dbfc7-eb89-726a-9e31-3cd7ee21452d',
  '019dbfc5-e395-7082-9214-20859f344cce'
];
async function get(path){
  const r = await fetch(base + path, { headers });
  const text = await r.text();
  let body = text; try { body = JSON.parse(text); } catch {}
  return { status:r.status, ok:r.ok, body };
}
const result = { generatedAt:new Date().toISOString(), partnerScopedProbe:await get('/partner/clubs'), adminPartnerClubs:{} };
for (const id of partnerIds) result.adminPartnerClubs[id] = await get(`/admin/partners/${id}/clubs`);
await fs.writeFile(out, JSON.stringify(result,null,2),'utf8');
NODE

  if [ -n "$PHPCLI" ]; then
    (cd "$APP" && "$PHPCLI" artisan agent:export-tools) > "$SCHEMA_OUT" 2>&1 || true
    [ -s "$APP/storage/api-docs/agent-tools-generic.json" ] && cp -f "$APP/storage/api-docs/agent-tools-generic.json" "$SCHEMA_JSON"
    (cd "$APP" && "$PHPCLI" artisan route:list --path=api/agent/v1 --json) > "$ROUTES_JSON" 2>&1 || true
    (cd "$APP" && "$PHPCLI" artisan help agent:export-tools) > "$EXPORT_HELP" 2>&1 || true
  fi

  git add requests/campaigns results/campaigns 2>/dev/null || true
  if ! git diff --cached --quiet; then
    git commit -m "PlacesRewards campaign result sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
