# 서버 설정 기록

VPS(Ubuntu 24.04, Docker 29, 기존 사이트: deskora, g7-chatbot, webstack)에 이 프로젝트를 올리기 위해 실행한 작업입니다.
비밀값은 적지 않습니다. 비밀값 목록은 [docs/operations/secrets.md](../docs/operations/secrets.md)를 보세요.

원칙
- 기존 컨테이너는 재시작·수정하지 않는다. 예외는 Caddy 설정 **덧붙이기 + reload**, Caddy를 `acp-edge` 네트워크에 **추가 연결**하는 것 두 가지.
- 이 프로젝트의 DB는 기존 MySQL을 공유하지 않는다.
- 서버에서 이미지를 빌드하지 않는다(깃헙에서 빌드해 GHCR에서 받음).
- IP 차단은 앱에서만 한다(서버 방화벽 수정 없음).

## 1. 임시 관리 계정 (작업 후 삭제)

사용자가 직접 실행: `claude-setup` 계정 생성, 서버 설정용 공개키 등록, 비밀번호 없는 sudo 부여(`/etc/sudoers.d/claude-setup`).
설정이 끝나면 `sudo deluser --remove-home claude-setup && sudo rm /etc/sudoers.d/claude-setup`으로 삭제.

## 2. 네트워크와 배포 계정 (2026-11-26)

```bash
docker network create --subnet 172.30.0.0/16 acp-edge      # 기존 대역(172.17~172.20)과 겹치지 않음
docker network connect acp-edge webstack-caddy-1             # 재시작 없음, 기존 web_proxy 연결 유지
sudo adduser --disabled-password --gecos "" acp-deploy       # 비밀번호 로그인 없음, sudo 없음
sudo usermod -aG docker acp-deploy
sudo mkdir -p /opt/acp/bin /opt/acp/staging /opt/acp/prod
sudo install -o root -g root -m 755 deploy.sh   /opt/acp/bin/deploy.sh   # 저장소 deploy/deploy.sh
sudo install -o root -g root -m 644 compose.yml /opt/acp/compose.yml     # 저장소 docker/compose.yml
sudo chown acp-deploy:acp-deploy /opt/acp/staging /opt/acp/prod && sudo chmod 750 /opt/acp/staging /opt/acp/prod
```

배포 키는 환경마다 하나씩, 서버가 키마다 배포할 환경을 고정합니다(`/home/acp-deploy/.ssh/authorized_keys`, 600):

```
restrict,command="/opt/acp/bin/deploy.sh staging" ssh-ed25519 ... acp-deploy-staging
restrict,command="/opt/acp/bin/deploy.sh prod"    ssh-ed25519 ... acp-deploy-production
```

확인: 스테이징 키로 `deploy prod` → 거부(2), 운영 키로 `deploy staging` → 거부(2), 각자 환경 → 통과(0).

## 3. 환경 설정 파일

`/opt/acp/staging/.env`, `/opt/acp/prod/.env` (소유자 acp-deploy, 600). 항목은 [docker/env.example](../docker/env.example)과 같고,
`APP_KEY`·DB 비밀번호는 서버에서 `openssl rand`로 환경마다 다르게 생성(화면 출력 없음).
`MAIL_PASSWORD`(Resend API 키)와 `GITHUB_INCIDENT_TOKEN`은 사용자가 직접 입력:

```bash
read -rsp "Resend API key: " K; echo; read -rsp "장애 이슈 토큰: " T; echo
for e in staging prod; do sudo sed -i "s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=$K|; s|^GITHUB_INCIDENT_TOKEN=.*|GITHUB_INCIDENT_TOKEN=$T|" /opt/acp/$e/.env; done; unset K T
```

## 4. Caddy

`/opt/webstack/Caddyfile`은 파일 하나만 컨테이너에 연결돼 있어, 파일을 통째로 바꾸면 컨테이너가 못 볼 수 있습니다. 그래서 **끝에 덧붙이기**로 수정했습니다.

```bash
sudo cp -p /opt/webstack/Caddyfile /opt/webstack/Caddyfile.bak-20261126
cat caddy-snippet.caddy | sudo tee -a /opt/webstack/Caddyfile      # 저장소 deploy/caddy-snippet.caddy
docker exec webstack-caddy-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker exec webstack-caddy-1 caddy reload   --config /etc/caddy/Caddyfile --adapter caddyfile
```

확인: 기존 사이트 2곳 200, `acp-staging.trueway.kr`·`acp.trueway.kr` 인증서 발급 정상(앱 배포 전 502).

DNS(Cloudflare): `acp-staging`, `acp` A 레코드 → VPS IP, **프록시 끔**(켜면 앱의 IP 차단이 Cloudflare IP를 막게 됨).

## 되돌리기

```bash
docker compose -p acp-staging -f /opt/acp/compose.yml down    # 각 환경(볼륨까지 지우려면 -v)
docker compose -p acp-prod    -f /opt/acp/compose.yml down
sudo cp -p /opt/webstack/Caddyfile.bak-20261126 /opt/webstack/Caddyfile   # 내용 복원(같은 inode로 덮어씀)
docker exec webstack-caddy-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
docker network disconnect acp-edge webstack-caddy-1 && docker network rm acp-edge
sudo deluser --remove-home acp-deploy && sudo rm -rf /opt/acp
```

## 5. compose 파일 변경은 서버에 수동 반영

배포 스크립트는 서버의 `/opt/acp/compose.yml`(root 소유)을 씁니다. AI나 자동 배포가 서버 구성을 바꿀 수 없게 하려는 것이라,
저장소의 `docker/compose.yml`이 바뀌어도 서버에는 자동으로 반영되지 않습니다. 해당 PR이 머지되면 다음을 실행합니다.

```bash
git show origin/main:docker/compose.yml > compose.yml                     # 머지된 내용
sudo cp -p /opt/acp/compose.yml /opt/acp/compose.yml.bak-$(date +%Y%m%d%H%M)
sudo install -o root -g root -m 644 compose.yml /opt/acp/compose.yml
SHA=$(sudo cat /opt/acp/staging/last_sha)                                 # 이미지는 그대로, 설정만 다시 반영
sudo -u acp-deploy env ACP_ENV_FILE=/opt/acp/staging/.env APP_IMAGE=ghcr.io/yoosook75/ai-collab-pipeline:$SHA \
  docker compose -p acp-staging --env-file /opt/acp/staging/.env -f /opt/acp/compose.yml up -d
```

기록: 2026-11-26 PR #9(상태 점검 수정) 머지 후 위 절차로 반영 → web healthy, worker/scheduler 점검 없음.

## 6. Caddy 변경 규칙 (기존 사이트와 공유하는 단일 입구)

`webstack-caddy-1`은 이 프로젝트와 기존 사이트(deskora, g7-chatbot)가 **함께 쓰는 유일한 입구**입니다.
컨테이너·네트워크·DB는 분리돼 있지만, Caddy가 멈추면 모든 사이트가 같이 멈춥니다.

| 상황 | 기존 사이트 영향 |
|---|---|
| 잘못된 설정으로 **reload** | 없음 — Caddy는 새 설정 적용에 실패하면 기존 설정으로 계속 동작 |
| 잘못된 설정이 파일에 남은 채 **재시작**(재부팅, 도커 업데이트 포함) | **전부 중단** — 시작 자체가 실패 |

그래서 Caddy 변경은 항상 이 순서로만 합니다.

1. 백업: `sudo cp -p /opt/webstack/Caddyfile /opt/webstack/Caddyfile.bak-$(date +%Y%m%d%H%M)`
2. 수정은 **덧붙이기**로(`sudo tee -a`). 파일 하나만 컨테이너에 연결돼 있어 통째로 바꾸면 컨테이너가 못 볼 수 있음
3. 검증: `docker exec webstack-caddy-1 caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile`
   - 실패하면 즉시 백업으로 되돌림(`sudo cp -p 백업 /opt/webstack/Caddyfile`) — 잘못된 설정을 파일에 남겨 두지 않음
4. 반영은 **reload만**: `docker exec webstack-caddy-1 caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile`
   - `docker restart`, `docker compose restart`는 쓰지 않음
5. 확인: 모든 사이트에 `curl -sI https://<도메인>/` → 200/30x

감시: `uptime` 워크플로가 10분마다 이 프로젝트(`/up`)와 기존 사이트(비밀값 `EXTRA_UPTIME_URLS`, 한 줄에 하나)를 밖에서 확인하고,
이상이 있으면 디스코드 `#ops-alerts`로 알립니다.

나중에 더 분리하려면: 메인 Caddyfile에 `import` 한 줄만 두고 이 프로젝트 블록을 별도 파일로 옮깁니다.
단, 지금은 파일 하나만 연결돼 있어 폴더를 연결하려면 **Caddy 재시작이 한 번 필요**하므로(기존 사이트가 잠깐 끊김) 계획된 점검 시간에 합니다.

## 보안 감시·로그 보관 (2026-11-26 적용)

### SSH
- `/etc/ssh/sshd_config.d/10-hardening.conf`에서 비밀번호 로그인을 끄고 키로만 접속(root는 `prohibit-password`)
- 되돌리기: 파일 삭제 → `sudo sshd -t && sudo systemctl reload ssh`
- 비상 접속: Contabo 패널의 VNC 콘솔(root 비밀번호)

### Caddy 접속 기록
- 네 사이트 블록 맨 앞에 `import accesslog <이름>` 한 줄씩(스니펫 `(accesslog)`은 파일 맨 위)
- 기록 위치(호스트): `/var/lib/docker/volumes/webstack_caddy_data/_data/access/<이름>.log`
  - JSON 형식, 50MiB마다 순환, 30일 보관
  - Cookie·Authorization 헤더는 Caddy가 기록하지 않음
- 백업: `/opt/webstack/Caddyfile.bak-20261126-accesslog`
- 파일이 컨테이너에 단일 파일로 연결돼 있으므로 내용만 덮어씀(`cat 새파일 > /opt/webstack/Caddyfile`). 그다음 위의 검증·reload 순서를 따름

### `.env` 접근 감시 (AI 호출 없음): `deploy/ops/env_watch.py` → `/opt/acp/bin/env_watch.py`
| 타이머 | 시각 | 하는 일 |
|---|---|---|
| `acp-envwatch-daily.timer` | 매일 09:00 KST | 전날 `.env` 요청 전체 건수·응답 코드별·사이트별 건수를 `#ops-alerts`로 1회 보냄 |
| `acp-envwatch-watch.timer` | 5분마다 | `.env` 경로에 **HTML이 아닌 2xx** 응답이 나가면 즉시 critical 경보 |

- 2xx이면서 HTML인 응답은 SPA가 홈페이지를 대신 돌려준 것으로 보고 "파일 노출 아님"으로 따로 셈
  - 예: deskora의 `/.env`는 홈페이지와 같은 파일임을 확인함
- 추가 분석은 가림 처리된 기록만 씀: `sudo python3 /opt/acp/bin/env_watch.py extract --date YYYY-MM-DD`
  - 쿼리 값은 `***`, IP 마지막 자리는 `x`, 헤더는 User-Agent만 남김
- 앱의 탐색 경로 IP 차단은 계속하되, 건별 디스코드 알림은 끔(`SECURITY_NOTIFY_PROBE_BLOCKS=false`). 대량 요청 차단 알림은 그대로 즉시 보냄
- 테스트: `python -m unittest deploy/ops/test_env_watch.py`

### 로그 보관 30일
- `/etc/logrotate.d/rsyslog`: daily, rotate 30
- `/etc/logrotate.d/btmp`: daily, rotate 30, compress
- 원본 백업: `/var/backups/acp-logcfg-20261126/`
- `/etc/systemd/journald.conf.d/30-retention.conf`: `MaxRetentionSec=30day`, `SystemMaxUse=1G`
- 조사용 원본 로그는 `/var/lib/acp-evidence/2026-11-26/`에 있음
  - root 전용, `SHA256SUMS` 포함
  - `/var/log` 밖에 있어 자동 삭제 대상이 아님
- 남은 일: 기존 컨테이너(deskora, g7, webstack)의 도커 로그 순환은 재시작이 필요하므로 점검 시간에 적용
