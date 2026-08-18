#!/usr/bin/env bash
set -Eeuo pipefail
AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
PLAN="${1:-}"
[ -n "$PLAN" ] || { echo "Usage: $0 /absolute/path/to/campaign-plan.json"; exit 2; }
cd "$AGENT"
"$NODE" scripts/campaign-control.mjs refresh >/dev/null
"$NODE" scripts/campaign-control.mjs execute "$PLAN"
