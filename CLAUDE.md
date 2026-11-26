# AI 작업 규칙

이 저장소는 "AI가 작성하고 사람이 승인하는" 협업 구조를 보여 주는 포트폴리오 프로젝트입니다.
라라벨 13 / PHP 8.4 / Pest. 설계: `docs/superpowers/specs/2026-11-26-ai-collab-pipeline-design.md`.

## 작업 방식

1. 테스트를 먼저 작성하고 실패하는 것을 확인한 뒤 구현합니다(`tests/Feature`, `tests/Unit`).
2. PR을 열기 전에 모두 통과해야 합니다.
   - `vendor/bin/pest`
   - `vendor/bin/pint --test` (실패하면 `vendor/bin/pint`로 정리)
   - `vendor/bin/phpstan analyse --memory-limit=1G`
3. 작업 브랜치는 `claude/`로 시작합니다. 완료되면 `gh pr create`로 PR을 엽니다.
4. PR 본문은 한국어로, 다음 세 부분을 씁니다: **무엇을**, **왜**, **테스트 방법**. 관련 이슈는 `Closes #번호`로 연결합니다.

## 하지 말 것

- `.github/`, `deploy/`, `docker/` 수정은 이슈에서 명시적으로 요청한 경우에만 합니다.
- 비밀값, 토큰, `.env` 내용, 서버 주소를 출력하거나 커밋하지 않습니다.
- 운영 서버나 운영 로그에 접근하려 하지 않습니다. 장애 이슈는 이슈 본문의 가림 처리된 정보만 사용합니다.
- 보안 로직(`app/Security/`, `app/Http/Middleware/`)의 차단 기준을 느슨하게 바꾸지 않습니다. 바꿔야 하면 PR 본문에 이유를 적습니다.
- main 브랜치에 직접 푸시하지 않습니다. 머지는 사람이 합니다.
