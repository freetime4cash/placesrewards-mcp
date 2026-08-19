#!/usr/bin/env bash
set -u

AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
APP="/home/placevle/app.placesrewards.com"
LOG="$AGENT/cron-worker.log"
SCHEMA_OUT="$AGENT/results/campaigns/live-agent-tools-export.txt"
PHP_DIAG="$AGENT/results/campaigns/php-cli-diagnostic.txt"

cd "$AGENT" || exit 1

# Self-heal the cron schedule to every minute.
DESIRED="* * * * * /bin/bash $AGENT/run-worker-cron.sh"
CURRENT="$(crontab -l 2>/dev/null || true)"
CLEANED="$(printf '%s\n' "$CURRENT" | grep -v '/placesrewards-agent-server/run-worker-cron\.sh' || true)"
{
  printf '%s\n' "$CLEANED"
  printf '%s\n' "$DESIRED"
} | awk 'NF && !seen[$0]++' | crontab -

# Discover a genuine PHP CLI binary instead of php-cgi.
PHPCLI=""
for CANDIDATE in \
  /opt/cpanel/ea-php84/root/usr/bin/php \
  /usr/local/bin/php \
  /usr/bin/php \
  /usr/local/bin/ea-php84 \
  /opt/alt/php84/usr/bin/php
 do
  if [ -x "$CANDIDATE" ]; then
    if "$CANDIDATE" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
      PHPCLI="$CANDIDATE"
      break
    fi
  fi
done

mkdir -p "$AGENT/bin"
if [ -n "$PHPCLI" ]; then
  ln -sf "$PHPCLI" "$AGENT/bin/php"
  export PATH="$AGENT/bin:$PATH"
fi

{
  echo "PHPCLI=$PHPCLI"
  if [ -n "$PHPCLI" ]; then
    "$PHPCLI" -r 'echo "SAPI=" . PHP_SAPI . PHP_EOL; echo "VERSION=" . PHP_VERSION . PHP_EOL;'
  else
    echo "SAPI=NOT_FOUND"
  fi
} > "$PHP_DIAG" 2>&1

{
  echo "===== $(date -Iseconds) ====="

  # Pull ChatGPT/GitHub-authored campaign requests.
  git fetch origin server-runtime
  git reset --hard origin/server-runtime

  # Recreate the CLI shim after reset in case repo cleanup touched it.
  mkdir -p "$AGENT/bin"
  if [ -n "$PHPCLI" ]; then
    ln -sf "$PHPCLI" "$AGENT/bin/php"
    export PATH="$AGENT/bin:$PATH"
  fi

  # Existing autonomous job queue.
  "$NODE" worker.js

  # GitHub campaign request queue.
  "$NODE" scripts/github-campaign-worker.mjs

  # Re-export live Agent API tool schemas with the correct CLI binary.
  if [ -n "$PHPCLI" ]; then
    rm -f "$SCHEMA_OUT"
    (
      cd "$APP" || exit 1
      "$PHPCLI" artisan agent:export-tools
    ) > "$SCHEMA_OUT" 2>&1 || true
  fi

  # Publish campaign results and diagnostics back to GitHub.
  git add requests/campaigns results/campaigns 2>/dev/null || true

  if ! git diff --cached --quiet; then
    git commit -m "PlacesRewards campaign result sync $(date -Iseconds)" || true
    git push origin HEAD:server-runtime || true
  fi
} >> "$LOG" 2>&1
