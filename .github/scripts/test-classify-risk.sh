#!/usr/bin/env bash
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
fail=0
run() { printf '%b' "$1" > "$tmp"; bash "$here/classify-risk.sh" "$tmp"; }
check() { if [ "$2" = "$3" ]; then echo "ok   - $1"; else echo "FAIL - $1: expected [$3] got [$2]"; fail=1; fi; }

check "plain model change is low"   "$(run 'app/Models/Post.php\n' | head -1)" "low"
out="$(run 'app/Models/Post.php\napp/Security/Masker.php\n')"
check "security file is high"       "$(echo "$out" | head -1)" "high"
check "lists matched file"          "$(echo "$out" | sed -n 2p)" "app/Security/Masker.php"
check "only matched files listed"   "$(echo "$out" | wc -l | tr -d ' ')" "2"
check "workflow change is high"     "$(run '.github/workflows/ci.yml\n' | head -1)" "high"
check "nested migration is high"    "$(run 'database/migrations/2026_01_01_x.php\n' | head -1)" "high"
check "composer.lock is high"       "$(run 'composer.lock\n' | head -1)" "high"
check "env example is high"         "$(run '.env.example\n' | head -1)" "high"
check "lookalike path is low"       "$(run 'app/SecurityNotes.md\n' | head -1)" "low"
check "empty input is low"          "$(run '' | head -1)" "low"
exit $fail
