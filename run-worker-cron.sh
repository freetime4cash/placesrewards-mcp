#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

AGENT="/home/placevle/placesrewards-agent-server"
NODE="/home/placevle/nodevenv/placesrewards-agent-server/24/bin/node"
APP_URL="${PLACESREWARDS_HEALTH_URL:-https://app.placesrewards.com/en-us/admin/login}"
LOG="$AGENT/cron-worker.log"
LOCK="$AGENT/data/worker.lock"
FAIL_FILE="$AGENT/data/worker-consecutive-failures"
MAX_LOG_BYTES="${PLACESREWARDS_WORKER_MAX_LOG_BYTES:-10485760}"
MAX_FAILURES="${PLACESREWARDS_WORKER_MAX_FAILURES:-3}"
COOLDOWN_SECONDS="${PLACESREWARDS_WORKER_COOLDOWN_SECONDS:-3600}"
STEP_TIMEOUT="${PLACESREWARDS_WORKER_STEP_TIMEOUT:-240}"

cd "$AGENT"
mkdir -p "$AGENT/data"

if [ ! -x "$NODE" ]; then
  printf '%s ERROR node runtime missing: %s\n' "$(date -Iseconds)" "$NODE" >&2
  exit 1
fi

# Never allow overlapping cron runs.
exec 9>"$LOCK"
if ! flock -n 9; then
  exit 0
fi

# Keep the worker log bounded without creating another large rotated copy.
if [ -f "$LOG" ]; then
  LOG_BYTES="$(wc -c < "$LOG" 2>/dev/null || printf '0')"
  if [ "$LOG_BYTES" -gt "$MAX_LOG_BYTES" ]; then
    : > "$LOG"
    printf '%s WARN worker log exceeded %s bytes and was truncated\n' "$(date -Iseconds)" "$MAX_LOG_BYTES" >> "$LOG"
  fi
fi

exec >> "$LOG" 2>&1
printf '===== %s =====\n' "$(date -Iseconds)"

# Back off for one hour after repeated failures.
FAILURES=0
if [ -f "$FAIL_FILE" ]; then
  read -r FAILURES < "$FAIL_FILE" || FAILURES=0
fi
case "$FAILURES" in
  ''|*[!0-9]*) FAILURES=0 ;;
esac
if [ "$FAILURES" -ge "$MAX_FAILURES" ]; then
  NOW="$(date +%s)"
  LAST_FAILURE="$(stat -c %Y "$FAIL_FILE" 2>/dev/null || printf '0')"
  AGE="$((NOW - LAST_FAILURE))"
  if [ "$AGE" -lt "$COOLDOWN_SECONDS" ]; then
    printf '%s WARN cooldown active after %s consecutive failures\n' "$(date -Iseconds)" "$FAILURES"
    exit 0
  fi
fi

# Refuse work if the account can no longer create and remove a small file.
PROBE="$AGENT/data/.quota-write-probe.$$"
if ! dd if=/dev/zero of="$PROBE" bs=1024 count=64 conv=fsync status=none 2>/dev/null; then
  printf '%s ERROR quota write probe failed; worker aborted\n' "$(date -Iseconds)"
  exit 70
fi
rm -f "$PROBE"

# Never run automation against an unhealthy customer application.
if ! curl -fsSL --max-time 25 -o /dev/null "$APP_URL"; then
  printf '%s ERROR application health check failed: %s\n' "$(date -Iseconds)" "$APP_URL"
  exit 69
fi

# Validation mode performs no queue, repair, campaign, or production writes.
if [ "${1:-}" = "--check" ]; then
  "$NODE" --check worker.js
  "$NODE" --check scripts/github-repair-worker.mjs
  "$NODE" --check scripts/github-campaign-worker.mjs
  printf '%s OK worker validation passed\n' "$(date -Iseconds)"
  exit 0
fi

run_step() {
  local name="$1"
  shift
  printf '%s START %s\n' "$(date -Iseconds)" "$name"
  if timeout --signal=TERM --kill-after=15s "${STEP_TIMEOUT}s" "$@"; then
    printf '%s DONE %s\n' "$(date -Iseconds)" "$name"
    return 0
  else
    local code=$?
    printf '%s ERROR %s exit=%s\n' "$(date -Iseconds)" "$name" "$code"
    return "$code"
  fi
}

FAILED=0
export PLACESREWARDS_AGENT_MAX_JOBS_PER_RUN="${PLACESREWARDS_AGENT_MAX_JOBS_PER_RUN:-10}"
export AUTOPILOT_MAX_NEW_OBJECTIVES="${AUTOPILOT_MAX_NEW_OBJECTIVES:-2}"

run_step core-worker "$NODE" worker.js || FAILED=1
run_step repair-worker "$NODE" scripts/github-repair-worker.mjs || FAILED=1
run_step campaign-worker "$NODE" scripts/github-campaign-worker.mjs || FAILED=1

if [ "$FAILED" -eq 0 ]; then
  printf '0\n' > "$FAIL_FILE"
  printf '%s OK worker cycle completed\n' "$(date -Iseconds)"
  exit 0
fi

FAILURES="$((FAILURES + 1))"
printf '%s\n' "$FAILURES" > "$FAIL_FILE"
printf '%s ERROR worker cycle failed; consecutive_failures=%s\n' "$(date -Iseconds)" "$FAILURES"
exit 1
