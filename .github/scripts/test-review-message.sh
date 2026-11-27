#!/usr/bin/env bash
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
fail=0
check() { if [ "$2" = "$3" ]; then echo "ok   - $1"; else echo "FAIL - $1: expected [$3] got [$2]"; fail=1; fi; }

msg() { bash "$here/review-message.sh"; }
line() { sed -n "${1}p"; }
rest() { tail -n +3; }

title='feat(ops): PHP 보안 패치 자동 적용 (보안 릴리스일 때만, 버전업 없음)'

out="$(PR_NUMBER=22 PR_TITLE="$title" PHP_RESULT=success SECRET_RESULT=success RISK=high \
  RISK_FILES=$'deploy/ops/php_secpatch.py\n.github/workflows/ci.yml' msg)"
check "pass+high: warn"          "$(printf '%s\n' "$out" | line 1)" "warn"
check "pass+high: title"         "$(printf '%s\n' "$out" | line 2)" "담당자 검토 요청 · #22 PHP 보안 패치 자동 적용"
check "pass+high: next step"     "$(printf '%s\n' "$out" | rest | head -1)" "자동 검사 통과 · 병합 전 검토 필요"
check "pass+high: lists files"   "$(printf '%s\n' "$out" | rest | grep -c '^- ')" "2"

out="$(PR_NUMBER=7 PR_TITLE='fix: 장바구니 수량 오류' PHP_RESULT=success SECRET_RESULT=success RISK=low RISK_FILES='' msg)"
check "pass+low: info"           "$(printf '%s\n' "$out" | line 1)" "info"
check "pass+low: title"          "$(printf '%s\n' "$out" | line 2)" "담당자 검토 요청 · #7 장바구니 수량 오류"
check "pass+low: summary"        "$(printf '%s\n' "$out" | rest)" "자동 검사 통과 · 병합 전 검토 필요"

out="$(PR_NUMBER=8 PR_TITLE='docs: README' PHP_RESULT=failure SECRET_RESULT=success RISK=high RISK_FILES='app/Security/Guard.php' msg)"
check "fail: warn"               "$(printf '%s\n' "$out" | line 1)" "warn"
check "fail: title"              "$(printf '%s\n' "$out" | line 2)" "검사 실패 · #8 README"
check "fail: says what failed"   "$(printf '%s\n' "$out" | rest | head -1)" "수정 필요 · 실패한 검사: 테스트·정적분석"
check "fail: keeps risk note"    "$(printf '%s\n' "$out" | rest | grep -c '위험도 높음')" "1"

out="$(PR_NUMBER=9 PR_TITLE='x' PHP_RESULT=cancelled SECRET_RESULT=success RISK=low RISK_FILES='' msg)"
check "cancelled: no message"    "$(printf '%s\n' "$out" | line 1)" "skip"

check "plain title kept"         "$(PR_NUMBER=1 PR_TITLE='로그인 개선' PHP_RESULT=success SECRET_RESULT=success RISK=low RISK_FILES='' msg | line 2)" "담당자 검토 요청 · #1 로그인 개선"
exit $fail
