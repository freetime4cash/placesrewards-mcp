#!/usr/bin/env bash
set -u

AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
APP="/home/placevle/app.placesrewards.com"
LOG="$AGENT/cron-worker.log"
SCHEMA_OUT="$AGENT/results/campaigns/live-agent-tools-export.txt"

cd "$AGENT" || exit 1

# Self-heal the cron schedule to every minute.
DESIRED="* * * * * /bin/bash $AGENT/run-worker-cron.sh"
CURRENT="$(crontab -l 2>/dev/null || true)"
CLEANED="$(printf '%s\n' "$CURRENT" | grep -v '/placesrewards-agent-server/run-worker-cron\.sh' || true)"
{
  printf '%s\n' "$CLEANED"
  printf '%s\n' "$DESIRED"
} | awk 'NF && !seen[$0]++' | crontab -

{
  echo "===== $(date -Iseconds) ====="

  # Pull ChatGPT/GitHub-authored campaign requests.
  git fetch origin server-runtime
  git reset --hard origin/server-runtime

  # Existing autonomous job queue.
  "$NODE" worker.js

  # GitHub campaign request queue.
  "$NODE" scripts/github-campaign-worker.mjs

  # Export the live Agent API tool schemas once for authoritative payload definitions.
  if [ ! -s "$SCHEMA_OUT" ]; then
    (
      cd "$APP" || exit 1
      php artisan agent:export-tools
    ) > "$SCHEMA_OUT" 2>&1 || true
  fi

  # Publish campaign results and schema export back to GitHub.
  git add requests/campaigns results/campaigns 2>/dev/null || true

  if ! git diff --cached --quiet; then
    git commit -m "PlacesRewards campaign result sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
