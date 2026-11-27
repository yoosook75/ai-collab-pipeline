#!/usr/bin/env bash
# Builds the PR result notification after the required checks finish.
# Inputs (env): PR_NUMBER, PR_TITLE, PHP_RESULT, SECRET_RESULT (success|failure|cancelled|skipped),
#               RISK (high|low), RISK_FILES (newline-separated)
# Output: line 1 = level (info|warn|skip), line 2 = title, rest = summary.
set -euo pipefail

# "feat(ops): 제목 (부연)" -> "제목": 알림에서는 사람이 읽는 부분만
short="$(printf '%s' "${PR_TITLE:-}" | sed -E 's/^[a-z]+(\([^)]*\))?!?:[[:space:]]*//; s/[[:space:]]*\([^)]*\)[[:space:]]*$//')"
[ -n "$short" ] || short="${PR_TITLE:-}"
ref="#${PR_NUMBER:-?} $short"

risk_note=""
if [ "${RISK:-low}" = high ]; then
  risk_note="위험도 높음: 아래 파일이 바뀌었어요"
  while IFS= read -r f; do
    [ -n "$f" ] && risk_note+=$'\n'"- $f"
  done <<< "${RISK_FILES:-}"
fi

# 새 커밋으로 검사가 취소된 경우는 알리지 않음 (곧 새 결과가 옴)
if [ "${PHP_RESULT:-}" = cancelled ] || [ "${SECRET_RESULT:-}" = cancelled ]; then
  echo skip
  exit 0
fi

if [ "${PHP_RESULT:-}" = success ] && [ "${SECRET_RESULT:-}" = success ]; then
  [ "${RISK:-low}" = high ] && echo warn || echo info
  echo "담당자 검토 요청 · $ref"
  echo "자동 검사 통과 · 병합 전 검토 필요"
  [ -z "$risk_note" ] || printf '\n%s\n' "$risk_note"
  exit 0
fi

failed=()
[ "${PHP_RESULT:-}" = success ] || failed+=("테스트·정적분석")
[ "${SECRET_RESULT:-}" = success ] || failed+=("비밀키 검사")
echo warn
echo "검사 실패 · $ref"
echo "수정 필요 · 실패한 검사: $(IFS=,; echo "${failed[*]}" | sed 's/,/, /g')"
[ -z "$risk_note" ] || printf '\n%s\n' "$risk_note"
