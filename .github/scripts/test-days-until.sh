#!/usr/bin/env bash
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   - $1"; else echo "FAIL - $1: expected [$3] got [$2]"; fail=1; fi; }

check "30 days ahead"    "$(TODAY=2026-11-26 bash "$here/days-until.sh" 2026-12-26)" "30"
check "same day is 0"    "$(TODAY=2026-11-26 bash "$here/days-until.sh" 2026-11-26)" "0"
check "past is negative" "$(TODAY=2026-11-26 bash "$here/days-until.sh" 2026-11-25)" "-1"
check "across a year"    "$(TODAY=2026-12-31 bash "$here/days-until.sh" 2027-01-01)" "1"
TODAY=2026-11-26 bash "$here/days-until.sh" bad >/dev/null 2>&1; check "bad format exits 2" "$?" "2"
TODAY=2026-11-26 bash "$here/days-until.sh" >/dev/null 2>&1; check "missing arg exits 2" "$?" "2"
exit $fail
