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
