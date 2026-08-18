#!/usr/bin/env bash
set -u

AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
LOG="$AGENT/cron-worker.log"

cd "$AGENT" || exit 1

{
  echo "===== $(date -Iseconds) ====="

  # Pull ChatGPT/GitHub-authored campaign requests.
  git fetch origin server-runtime
  git reset --hard origin/server-runtime

  # Existing autonomous job queue.
  "$NODE" worker.js

  # GitHub campaign request queue.
  "$NODE" scripts/github-campaign-worker.mjs

  # Publish campaign results back to GitHub.
  git add requests/campaigns results/campaigns 2>/dev/null || true

  if ! git diff --cached --quiet; then
    git commit -m "PlacesRewards campaign result sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
