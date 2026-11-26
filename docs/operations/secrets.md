# 비밀값 목록

이 문서에는 **값을 절대 적지 않습니다.** 무엇이 어디에 있고, 잃어버리거나 새면 어떻게 다시 만드는지만 적습니다.

원칙
- 값은 정해진 곳(깃헙 비밀값, 서버 `/opt/acp/<env>/.env`)에만 둡니다. 대화창, 메모 파일, 저장소 폴더에는 남기지 않습니다.
- 잃어버린 값은 다시 찾지 않고 **새로 발급해서 교체**합니다. 새었다고 의심되면 즉시 교체합니다.
- 만료가 있는 값은 저장소 변수에 만료일을 적어 두면 `token-expiry` 워크플로가 30일 전부터 디스코드로 알립니다.

## 깃헙 저장소 비밀값 (Settings → Secrets and variables → Actions)

| 이름 | 용도 | 만료 | 다시 만드는 방법 |
|---|---|---|---|
| `CLAUDE_CODE_OAUTH_TOKEN` | AI 작업(`claude`, `incident`, `ai-review`)이 Claude Max 구독으로 동작 | 1년 → 변수 `CLAUDE_TOKEN_EXPIRES` | PC에서 `claude setup-token` → `gh secret set CLAUDE_CODE_OAUTH_TOKEN -R yoosook75/ai-collab-pipeline` → 변수 날짜 갱신. 예전 토큰은 https://claude.ai/settings/claude-code 에서 삭제 |
| `ACP_APP_ID` | AI 봇(GitHub App `acp-ai-bot-yoosook75`) 번호 — 비밀은 아님 | 없음 | 앱 설정 화면에서 확인 |
| `ACP_APP_PRIVATE_KEY` | AI 봇 신분증. PR·댓글이 봇 이름으로 남음 | 없음 | 앱 설정 → Generate a private key → `gh secret set ACP_APP_PRIVATE_KEY < 키파일.pem` → 이전 키는 앱 설정에서 삭제, 내려받은 파일도 삭제 |
| `DISCORD_WEBHOOK_DEV` | `#dev-pipeline` 알림(이슈, 검사, 위험도, AI 작업, 배포) | 없음 | 디스코드 채널 설정 → 연동 → 웹후크 새로 만들기 → 비밀값 교체 → 이전 웹훅 삭제 |
| `DISCORD_WEBHOOK_OPS` | `#ops-alerts` 알림(가동 확인, 장애 복구, 운영 배포 실패) | 없음 | 위와 같음. 서버 `.env`의 `OPS_DISCORD_WEBHOOK`도 같이 교체 |

## 깃헙 환경 비밀값 (Settings → Environments)

배포 키는 **저장소 비밀값으로 만들지 않습니다.** 환경마다 다른 키를 쓰고, 서버는 키마다 배포할 수 있는 환경을 고정합니다.

| 환경 | 이름 | 용도 | 다시 만드는 방법 |
|---|---|---|---|
| `staging` | `DEPLOY_SSH_KEY` | 스테이징 배포 전용 키 | 새 키 생성 → 서버 `acp-deploy`의 `authorized_keys`에서 `deploy.sh staging` 줄의 공개키 교체 → 비밀값 교체 |
| `production` | `DEPLOY_SSH_KEY` | 운영 배포 전용 키(사용자 승인 뒤에만 사용) | 위와 같음(`deploy.sh prod` 줄) |
| 둘 다 | `DEPLOY_HOST`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_PORT`(선택) | 서버 주소와 서버 지문 | 서버 주소가 바뀌면 갱신. 지문은 `ssh-keyscan -p <포트> <주소>` |

## 서버 `.env` (`/opt/acp/staging/.env`, `/opt/acp/prod/.env`, 권한 600)

| 이름 | 용도 | 만료 | 다시 만드는 방법 |
|---|---|---|---|
| `APP_KEY` | 라라벨 암호화 키(환경마다 다름) | 없음 | 바꾸면 로그인 세션이 모두 끊김. 유출 시에만 `php artisan key:generate --show`로 새로 만들어 교체 |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | 이 프로젝트 전용 MySQL(환경마다 다름) | 없음 | DB 컨테이너에서 비밀번호 변경 후 `.env` 갱신 |
| `OPS_DISCORD_WEBHOOK` | 앱이 보내는 운영 감지 알림 | 없음 | 디스코드 `#ops-alerts` 웹훅과 같은 값 |
| `GITHUB_INCIDENT_TOKEN` | 앱이 깃헙에 장애 이슈 작성. Fine-grained 토큰 `acp-incident-issues`, 이 저장소 **Issues 쓰기만** | 1년 → 변수 `INCIDENT_TOKEN_EXPIRES` | https://github.com/settings/personal-access-tokens 에서 새로 발급 → 두 `.env` 교체 → 컨테이너 재시작 → 이전 토큰 삭제 → 변수 날짜 갱신 |
| `MAIL_PASSWORD` | Resend SMTP(사용자명 `resend`, 비밀번호 = API 키) | 없음 | Resend 대시보드에서 새 API 키 발급 → 두 `.env` 교체 → 이전 키 삭제 |

## 이 PC에만 있는 것

| 이름 | 위치 | 비고 |
|---|---|---|
| GitHub App 비공개 키 사본 | `C:\Users\yschu\.secrets\` | 비밀값 등록에 썼던 사본. 지워도 됨 |
| 서버 설정용 임시 SSH 키 `claude_vps` | `C:\Users\yschu\.ssh\` | 서버 설정이 끝나면 서버의 임시 계정과 함께 삭제 |
| `gh` 로그인 | Windows 자격 증명 | 평소 쓰는 깃헙 CLI 로그인 |
