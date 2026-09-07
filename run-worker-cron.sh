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
TOM_DEPLOY_LOG="$AGENT/results/campaigns/northeast-ohio-tom-v2-deploy.txt"
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
mkdir -p "$AGENT/bin" "$AGENT/data/backups" "$AGENT/requests/repairs" "$AGENT/results/repairs" "$AGENT/results/revenue" "$AGENT/results/control"
if [ -n "$PHPCLI" ]; then ln -sf "$PHPCLI" "$AGENT/bin/php"; export PATH="$AGENT/bin:$PATH"; fi

{
  echo "===== $(date -Iseconds) ====="
  git fetch origin server-runtime
  git reset --hard origin/server-runtime
  mkdir -p "$AGENT/bin" "$AGENT/results/revenue" "$AGENT/results/control"
  if [ -n "$PHPCLI" ]; then ln -sf "$PHPCLI" "$AGENT/bin/php"; export PATH="$AGENT/bin:$PATH"; fi

  "$NODE" scripts/verify-on-change.mjs || true
  "$NODE" worker.js
  "$NODE" scripts/github-repair-worker.mjs || true
  "$NODE" scripts/github-campaign-worker.mjs

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
    if [ -f "$AGENT/scripts/363-schema-inspector.php" ]; then
      "$PHPCLI" "$AGENT/scripts/363-schema-inspector.php" || true
    fi
    if [ -f "$AGENT/scripts/inspect-northeast-ohio-media.php" ]; then
      "$PHPCLI" "$AGENT/scripts/inspect-northeast-ohio-media.php" || true
    fi
    if [ -f "$AGENT/scripts/run-northeast-ohio-tom-v2.php" ]; then
      "$PHPCLI" "$AGENT/scripts/run-northeast-ohio-tom-v2.php" > "$TOM_DEPLOY_LOG" 2>&1 || true
    fi
    if [ -f "$AGENT/scripts/inspect-treasure-hunt-native-modules.php" ]; then
      "$PHPCLI" "$AGENT/scripts/inspect-treasure-hunt-native-modules.php" || true
    fi
    if [ -f "$AGENT/scripts/install-363-foundation-demo-v2.php" ]; then
      "$PHPCLI" "$AGENT/scripts/install-363-foundation-demo-v2.php" || true
    fi
    if [ -f "$AGENT/scripts/363-demo-link-inspector.php" ]; then
      "$PHPCLI" "$AGENT/scripts/363-demo-link-inspector.php" || true
    fi
  fi

  "$NODE" scripts/resolve-legacy-363-request.mjs || true
  "$NODE" scripts/autopilot.mjs || true

  git add requests/campaigns results/campaigns 2>/dev/null || true
  if ! git diff --cached --quiet -- requests/campaigns results/campaigns; then
    if "$NODE" scripts/semantic-result-diff.mjs; then
      git commit -m "PlacesRewards meaningful campaign state sync $(date -Iseconds)" || true
      git push origin HEAD:server-runtime || true
    else
      git restore --staged --worktree -- requests/campaigns results/campaigns 2>/dev/null || true
    fi
  fi

  git add requests/repairs results/repairs 2>/dev/null || true
  if ! git diff --cached --quiet -- requests/repairs results/repairs; then
    git commit -m "PlacesRewards repair state sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi

  git add results/revenue 2>/dev/null || true
  if ! git diff --cached --quiet -- results/revenue; then
    git commit -m "PlacesRewards revenue analytics contract sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi

  git add results/control 2>/dev/null || true
  if ! git diff --cached --quiet -- results/control; then
    git commit -m "PlacesRewards commercial control status sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
