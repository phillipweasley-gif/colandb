#!/usr/bin/env bash
# Recurring events saved from the wp-admin edit screen (Community Events
# Calendar 1.45.1): approving a series keeps each date's own day, and a
# schedule change regenerates the dates once, without per-date emails.
# Usage: WPTEST=<folder from tests/setup.sh> bash tests/recurrence-e2e.sh
S=${WPTEST:?set WPTEST to the folder created by tests/setup.sh}
REPO=$(cd "$(dirname "$0")/.." && pwd)
cd $S/wordpress
W="php $S/wp-cli.phar --allow-root"
rm -rf wp-content/plugins/community-events-calendar && cp -r $REPO/community-events-calendar wp-content/plugins/
: > wp-content/debug.log 2>/dev/null
OUT=$(timeout 120 $W eval-file $REPO/tests/recurrence-e2e.php 2>&1)
PASS=$(echo "$OUT" | grep -c '^1 '); FAIL=$((5 - PASS))   # 5 checks; a run that dies counts the rest as failed
echo "$OUT" | sed -e 's/^1 /PASS /' -e 's/^0 /FAIL /'
[ "$(echo "$OUT" | grep -c '^[01] ')" = 5 ] || echo "FAIL run stopped early (timed out or fatal error)"
echo; echo "debug.log (plugin-related):"; grep -i 'cec' wp-content/debug.log 2>/dev/null | tail -5
echo "RESULT: $PASS passed, $FAIL failed"
