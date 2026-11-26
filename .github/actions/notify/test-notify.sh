#!/usr/bin/env bash
# Runs notify.sh in dry-run mode and checks the JSON it would send.
set -euo pipefail
cd "$(dirname "$0")"
fail=0
check() { if eval "$2"; then echo "ok   - $1"; else echo "FAIL - $1"; fail=1; fi; }

out=$(NOTIFY_DRY_RUN=1 PROVIDER=discord CHANNEL=dev TITLE='PR 열림' LEVEL=warn SUMMARY='위험도 높음' URL='https://x.test/pr/1' bash notify.sh)
check "discord embed has url"   "echo '$out' | jq -e '.embeds[0].url == \"https://x.test/pr/1\"' >/dev/null"
check "discord warn color"      "echo '$out' | jq -e '.embeds[0].color == 15844367' >/dev/null"
check "discord title kept"      "echo '$out' | jq -e '.embeds[0].title == \"PR 열림\"' >/dev/null"

out=$(NOTIFY_DRY_RUN=1 PROVIDER=slack CHANNEL=ops TITLE='t' LEVEL=critical SUMMARY='s' URL='https://x.test' bash notify.sh)
check "slack has blocks"        "echo '$out' | jq -e '.blocks | length > 0' >/dev/null"
check "slack text includes url" "echo '$out' | jq -e '.blocks[0].text.text | contains(\"https://x.test\")' >/dev/null"

out=$(NOTIFY_DRY_RUN=1 PROVIDER=discord CHANNEL=dev TITLE='quote " and \ back' LEVEL=info SUMMARY='multi
line' URL='' bash notify.sh)
check "special chars escaped"   "echo \"\$out\" | jq -e '.embeds[0].title | contains(\"quote\")' >/dev/null"
check "empty url omitted"       "echo \"\$out\" | jq -e '.embeds[0] | has(\"url\") | not' >/dev/null"

set +e
PROVIDER=discord CHANNEL=dev TITLE=t LEVEL=info SUMMARY=s URL= DISCORD_WEBHOOK_DEV= bash notify.sh >/dev/null 2>&1
check "missing webhook is not an error" "[ $? -eq 0 ]"
PROVIDER=teams CHANNEL=dev TITLE=t LEVEL=info SUMMARY=s URL= NOTIFY_DRY_RUN=1 bash notify.sh >/dev/null 2>&1
check "unknown provider fails"  "[ $? -ne 0 ]"
set -e

exit $fail
