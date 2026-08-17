#!/usr/bin/env bash
set -u
AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
cd "$AGENT" || exit 1
"$NODE" worker.js >> "$AGENT/cron-worker.log" 2>&1
