#!/usr/bin/env bash
# Runs every smoke test in order, pausing so the login throttle (5 per minute) never trips.
# Start the server first:  PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0
# Usage (from the Laravel project root):  bash scripts/smoke-all.sh        (takes about 2.5 minutes)
cd "$(dirname "$0")/.." || exit 1
failed=0
run() { echo; echo "=================== $1"; node "scripts/$1" || failed=1; }

run smoke-core-flow.mjs            # 3 logins
run smoke-wallet-leaderboard.mjs   # 1 login  (4 so far)
echo; echo "(waiting 65 s for the login throttle)"; sleep 65
run smoke-admin.mjs                # 4 logins
echo; echo "(waiting 65 s for the login throttle)"; sleep 65
run smoke-notifications.mjs        # 2 logins

echo
if [ "$failed" -eq 0 ]; then echo "ALL SMOKE TESTS PASSED"; else echo "SOME SMOKE TESTS FAILED (see FAIL lines above)"; fi
exit "$failed"
