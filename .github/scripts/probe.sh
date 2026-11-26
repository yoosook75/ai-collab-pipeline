#!/usr/bin/env bash
# Usage: probe.sh <full-url>   e.g. https://acp.trueway.kr/up
# Prints the level (ok|warn|critical) on the first line, then one line per problem.
# Always exits 0 so a caller can probe several targets.
set -uo pipefail

url="${1:-}"
slow_after="${PROBE_SLOW_SECONDS:-3}"
cert_warn_days="${PROBE_CERT_WARN_DAYS:-14}"
here="$(cd "$(dirname "$0")" && pwd)"
level=ok
problems=()
raise() { # raise <warn|critical> <message>
  [ "$1" = critical ] && level=critical
  [ "$1" = warn ] && [ "$level" = ok ] && level=warn
  problems+=("$2")
}

if [ -z "$url" ]; then echo critical; echo "대상 주소가 비어 있음"; exit 0; fi

result=$(curl -s -o /dev/null -w '%{http_code} %{time_total}' --max-time 15 "$url" 2>/dev/null) || true
code=${result%% *}; secs=${result#* }
if [ -z "$result" ] || [ "$code" = "000" ]; then
  raise critical "응답 없음(접속 실패 또는 15초 초과)"
elif [ "$code" -ge 400 ]; then
  raise critical "응답 코드 $code"
elif awk "BEGIN{exit !($secs > $slow_after)}"; then
  raise warn "응답 느림: ${secs}초"
fi

if [[ "$url" == https://* ]]; then
  host=$(echo "$url" | sed -E 's#^https://([^/:]+).*#\1#')
  not_after=$(echo | timeout 15 openssl s_client -servername "$host" -connect "$host:443" 2>/dev/null \
    | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
  if [ -z "$not_after" ]; then
    raise warn "TLS 인증서를 확인할 수 없음"
  else
    days=$(bash "$here/days-until.sh" "$(date -u -d "$not_after" +%F)")
    [ "$days" -lt "$cert_warn_days" ] && raise warn "TLS 인증서 만료까지 ${days}일"
  fi
fi

echo "$level"
[ ${#problems[@]} -gt 0 ] && printf '%s\n' "${problems[@]}"
exit 0
