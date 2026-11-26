#!/usr/bin/env bash
# Usage: days-until.sh YYYY-MM-DD  -> whole days from today (UTC) to that date; negative if past.
# TODAY=YYYY-MM-DD overrides "today" (for tests).
set -euo pipefail

target="${1:-}"
today="${TODAY:-$(date -u +%F)}"
re='^[0-9]{4}-[0-9]{2}-[0-9]{2}$'

if [[ ! "$target" =~ $re ]] || [[ ! "$today" =~ $re ]]; then
  echo "usage: days-until.sh YYYY-MM-DD" >&2
  exit 2
fi

t=$(date -u -d "$target" +%s 2>/dev/null) || { echo "invalid date: $target" >&2; exit 2; }
n=$(date -u -d "$today" +%s)
echo $(( (t - n) / 86400 ))
