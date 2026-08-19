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
TOOL_SNIPPET="$AGENT/results/campaigns/agent-tool-service-snippet.txt"
REPAIR_OUT="$AGENT/results/campaigns/agent-tool-service-repair.txt"
TOOL_FILE="$APP/app/Services/Agent/AgentToolService.php"

cd "$AGENT" || exit 1

DESIRED="* * * * * /bin/bash $AGENT/run-worker-cron.sh"
CURRENT="$(crontab -l 2>/dev/null || true)"
CLEANED="$(printf '%s\n' "$CURRENT" | grep -v '/placesrewards-agent-server/run-worker-cron\.sh' || true)"
{
  printf '%s\n' "$CLEANED"
  printf '%s\n' "$DESIRED"
} | awk 'NF && !seen[$0]++' | crontab -

PHPCLI=""
for CANDIDATE in /opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/php /usr/bin/php /usr/local/bin/ea-php84 /opt/alt/php84/usr/bin/php; do
  if [ -x "$CANDIDATE" ] && "$CANDIDATE" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
    PHPCLI="$CANDIDATE"
    break
  fi
done

mkdir -p "$AGENT/bin" "$AGENT/data/backups"
if [ -n "$PHPCLI" ]; then
  ln -sf "$PHPCLI" "$AGENT/bin/php"
  export PATH="$AGENT/bin:$PATH"
fi

{
  echo "PHPCLI=$PHPCLI"
  if [ -n "$PHPCLI" ]; then "$PHPCLI" -r 'echo "SAPI=" . PHP_SAPI . PHP_EOL; echo "VERSION=" . PHP_VERSION . PHP_EOL;'; else echo "SAPI=NOT_FOUND"; fi
} > "$PHP_DIAG" 2>&1

{
  echo "===== $(date -Iseconds) ====="
  git fetch origin server-runtime
  git reset --hard origin/server-runtime

  mkdir -p "$AGENT/bin"
  if [ -n "$PHPCLI" ]; then ln -sf "$PHPCLI" "$AGENT/bin/php"; export PATH="$AGENT/bin:$PATH"; fi

  # Keep repaired source intact if the old corruption is ever reintroduced.
  if grep -q '\$tools = array_merge(\$tools, \$this->placesRewardsCampaignTools());' "$TOOL_FILE" 2>/dev/null; then
    BACKUP="$AGENT/data/backups/AgentToolService-pre-repair-$(date +%Y%m%d-%H%M%S).php"
    cp -p "$TOOL_FILE" "$BACKUP"
    python3 - "$TOOL_FILE" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text(encoding='utf-8')
old='''                // If the partner doesn't have that permission, the endpoint would \n        $tools = array_merge($tools, $this->placesRewardsCampaignTools());\n\n        return\n                // 403 FEATURE_DISABLED, so don't advertise it as an available tool.\n'''
new='''                // If the partner doesn't have that permission, the endpoint would\n                // 403 FEATURE_DISABLED, so don't advertise it as an available tool.\n'''
if old not in s: raise SystemExit('Exact corruption block not found; no change made.')
p.write_text(s.replace(old,new,1),encoding='utf-8')
PY
    if [ "$?" -eq 0 ] && [ -n "$PHPCLI" ]; then
      "$PHPCLI" -l "$TOOL_FILE" > "$REPAIR_OUT" 2>&1
      if [ "$?" -ne 0 ]; then cp -p "$BACKUP" "$TOOL_FILE"; echo "ROLLBACK" >> "$REPAIR_OUT"; else echo "REPAIR_APPLIED backup=$BACKUP" >> "$REPAIR_OUT"; fi
    else
      cp -p "$BACKUP" "$TOOL_FILE"
    fi
  fi

  "$NODE" worker.js
  "$NODE" scripts/github-campaign-worker.mjs

  sed -n '190,255p' "$TOOL_FILE" > "$TOOL_SNIPPET" 2>&1 || true

  if [ -n "$PHPCLI" ]; then
    (
      cd "$APP" || exit 1
      "$PHPCLI" artisan agent:export-tools
    ) > "$SCHEMA_OUT" 2>&1 || true
    [ -s "$APP/storage/api-docs/agent-tools-generic.json" ] && cp -f "$APP/storage/api-docs/agent-tools-generic.json" "$SCHEMA_JSON"

    (
      cd "$APP" || exit 1
      "$PHPCLI" artisan route:list --path=api/agent/v1 --json
    ) > "$ROUTES_JSON" 2>&1 || true

    (
      cd "$APP" || exit 1
      "$PHPCLI" artisan help agent:export-tools
    ) > "$EXPORT_HELP" 2>&1 || true
  fi

  git add requests/campaigns results/campaigns 2>/dev/null || true
  if ! git diff --cached --quiet; then
    git commit -m "PlacesRewards campaign result sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
