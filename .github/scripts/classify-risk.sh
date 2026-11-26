#!/usr/bin/env bash
# Usage: classify-risk.sh <file-with-changed-paths>
# Prints "high" or "low" on the first line, then each changed path matching .github/risk-paths.txt.
set -euo pipefail
shopt -s extglob globstar

here="$(cd "$(dirname "$0")" && pwd)"
patterns_file="${RISK_PATHS_FILE:-$here/../risk-paths.txt}"

mapfile -t patterns < <(grep -vE '^\s*(#|$)' "$patterns_file")
matched=()

while IFS= read -r path || [ -n "$path" ]; do
  [ -z "$path" ] && continue
  for pattern in "${patterns[@]}"; do
    # "dir/**" matches anything under dir/; other patterns use bash glob matching.
    if [[ "$pattern" == *'/**' ]]; then
      prefix="${pattern%/**}/"
      if [[ "$path" == "$prefix"* ]]; then matched+=("$path"); break; fi
    elif [[ "$path" == $pattern ]]; then
      matched+=("$path"); break
    fi
  done
done < "$1"

if [ "${#matched[@]}" -gt 0 ]; then
  echo high
  printf '%s\n' "${matched[@]}"
else
  echo low
fi
