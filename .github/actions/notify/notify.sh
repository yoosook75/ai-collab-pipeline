#!/usr/bin/env bash
# Sends one notification to Discord or Slack. Same message shape as the app's OpsNotifier.
# Inputs (env): PROVIDER (discord|slack), CHANNEL (dev|ops), TITLE, LEVEL (info|warn|critical), SUMMARY, URL
# Webhooks (env): DISCORD_WEBHOOK_DEV, DISCORD_WEBHOOK_OPS, SLACK_WEBHOOK_DEV, SLACK_WEBHOOK_OPS
# NOTIFY_DRY_RUN=1 prints the payload instead of sending it.
set -euo pipefail

PROVIDER="${PROVIDER:-discord}"
CHANNEL="${CHANNEL:-dev}"
LEVEL="${LEVEL:-info}"
TITLE="${TITLE:-}"
SUMMARY="${SUMMARY:-}"
URL="${URL:-}"

case "$LEVEL" in
  info)     color=3447003;  icon=':information_source:' ;;
  warn)     color=15844367; icon=':warning:' ;;
  critical) color=15158332; icon=':rotating_light:' ;;
  *) echo "unknown level: $LEVEL" >&2; exit 2 ;;
esac

case "$PROVIDER" in
  discord)
    payload=$(jq -n --arg t "$TITLE" --arg s "$SUMMARY" --arg u "$URL" --argjson c "$color" \
      '{embeds: [({title: $t, description: $s, color: $c} + (if $u == "" then {} else {url: $u} end))]}')
    var="DISCORD_WEBHOOK_${CHANNEL^^}"
    ;;
  slack)
    payload=$(jq -n --arg t "$TITLE" --arg s "$SUMMARY" --arg u "$URL" --arg i "$icon" \
      '{text: $t, blocks: [{type: "section", text: {type: "mrkdwn",
        text: ($i + " *" + $t + "*\n" + $s + (if $u == "" then "" else "\n<" + $u + "|열기>" end))}}]}')
    var="SLACK_WEBHOOK_${CHANNEL^^}"
    ;;
  *) echo "unknown provider: $PROVIDER" >&2; exit 2 ;;
esac

if [ "${NOTIFY_DRY_RUN:-0}" = "1" ]; then
  printf '%s\n' "$payload"
  exit 0
fi

webhook="${!var:-}"
if [ -z "$webhook" ]; then
  echo "::warning::$var is not set; skipping notification '$TITLE'"
  exit 0
fi

if ! curl -fsS --max-time 10 -H 'Content-Type: application/json' -d "$payload" "$webhook" >/dev/null; then
  echo "::warning::notification '$TITLE' failed to send"
fi
