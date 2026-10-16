#!/usr/bin/env bash
# Builds the frontend, starts a clean throwaway stack, runs the browser end-to-end test, tears everything down.
# Needs: php, nginx, redis-server, node on PATH; playwright-core (PW_PATH) and a Chromium binary (CHROME).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUN="${RUN:-/tmp/ls-run}"
export RUN

( cd "$ROOT/web" && node build.mjs >/dev/null ) || { echo "build failed"; exit 1; }
bash "$ROOT/scripts/e2e/stop-stack.sh" >/dev/null 2>&1
bash "$ROOT/scripts/e2e/${STACK_SCRIPT:-start-stack.sh}" >/dev/null || { echo "stack failed to start"; exit 1; }
TOKEN="$(cd "$ROOT/server" && LOCKSEND_DATA_DIR="$RUN/data" php bin/admin-setup-token.php | sed -n 3p | tr -d ' ')"
BASE="http://127.0.0.1:${PORT:-8088}" SETUP_TOKEN="$TOKEN" DATA_DIR="$RUN/data" node "$ROOT/scripts/e2e/e2e.mjs"
status=$?
if [ "${KEEP:-0}" != "1" ]; then bash "$ROOT/scripts/e2e/stop-stack.sh"; fi
exit $status
