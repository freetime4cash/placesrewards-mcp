#!/usr/bin/env bash
set -Eeuo pipefail
AGENT="/home/placevle/placesrewards-agent-server"
cd "$AGENT"

git add \
  .gitignore \
  GITHUB-RECOVERY.md \
  run-worker-cron.sh \
  package.json \
  server.js \
  worker.js \
  status.js \
  approve.js \
  lib scripts public README.txt \
  2>/dev/null || true

if ! git diff --cached --quiet; then
  git commit -m "PlacesRewards server runtime sync $(date -Iseconds)"
fi

git push origin HEAD:server-runtime
