#!/usr/bin/env bash
set -Eeuo pipefail
AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
cd "$AGENT"
"$NODE" scripts/campaign-control.mjs refresh
