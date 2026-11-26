# AI 협업 자동화 파이프라인 1단계 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 깃헙 이슈 → AI(Claude Code) PR → 자동 검사·위험도 → 사람 승인 → 스테이징 → 승인 후 운영으로 이어지는 흐름과, 운영 감지 → 자동 방어 → 디스코드 알림 → AI 복구 이슈·PR 흐름을 라라벨 게시판 위에 완성한다.

**Architecture:** 저장소 루트에 라라벨 앱(연습용 게시판 + 보안 감지 모듈)을 두고, `.github/`에 언어 독립적인 공통 흐름(AI 작업, 위험도, 알림, 배포)과 교체 가능한 PHP 검사 워크플로를 분리해 둔다. 이미지는 GitHub Actions에서 빌드해 GHCR에 올리고, VPS에서는 권한이 좁은 배포 계정의 강제 명령 스크립트가 스테이징/운영 compose 프로젝트를 갱신한다. 기존 `webstack-caddy-1`이 전용 외부 네트워크로 두 환경을 프록시한다.

**Tech Stack:** PHP 8.4, Laravel(최신 안정판), Pest, Larastan, Pint, MySQL 8.4, FrankenPHP 이미지, Docker Compose, GitHub Actions(`anthropics/claude-code-action@v1`, `actions/create-github-app-token`), gitleaks, Dependabot, Discord 웹훅.

**Spec:** `docs/superpowers/specs/2026-11-26-ai-collab-pipeline-design.md`

## Global Constraints

- AI 작업 단계(Claude Code 실행 잡)에는 서버 접속 비밀키를 일절 주지 않는다. 운영 배포 비밀키는 GitHub Environment `production`에만 둔다.
- 위험도와 상관없이 모든 PR은 사람이 승인한다. 자동 머지는 쓰지 않는다.
- 기존 VPS 컨테이너(`deskora-*`, `g7-chatbot-php`, `webstack-*`)는 재시작·수정하지 않는다. 예외: Caddyfile에 블록 **추가** + `caddy reload`, `webstack-caddy-1`을 `acp-edge` 네트워크에 연결(사용자 확인 후).
- DB는 `webstack-mysql-1`을 공유하지 않는다. 스테이징·운영 DB 분리.
- 서버에서 이미지를 빌드하지 않는다.
- IP 차단은 앱 미들웨어에서만 한다. iptables/ufw/fail2ban 금지.
- Claude 인증은 Max 구독 토큰(`CLAUDE_CODE_OAUTH_TOKEN` → `claude_code_oauth_token`). API 키를 만들지 않는다. 토큰 만료일은 저장소 변수 `CLAUDE_TOKEN_EXPIRES`(YYYY-MM-DD)에 둔다.
- AI 리뷰는 PR이 처음 열릴 때 한 번만. 감지 이슈는 같은 오류를 묶고 하루 상한을 둔다.
- `@claude` 호출은 `author_association`이 `OWNER` 또는 `COLLABORATOR`일 때만 실행.
- AI PR은 GitHub App(봇 신원) 토큰으로 연다.
- 공개 저장소: 비밀키, 서버 IP, 개인정보를 커밋하지 않는다. 공개 이슈에는 가림 처리된 요약만.
- 관리자 계정(`users.is_admin = true`)은 계정 잠금·글쓰기 제한·IP 차단 대상에서 제외. IP 예외에 의존하지 않는다.
- 감지·자동 대응은 스테이징과 운영 모두에서 동작. 시연은 스테이징.
- 알림 발송부는 교체 가능: 앱은 `OpsNotifier` 인터페이스, 워크플로는 `.github/actions/notify`(입력 `provider`: `discord`|`slack`).
- 모든 디스코드 알림에 관련 깃헙 링크 또는 앱 링크를 넣는다.
- 사용자 대상 문서(README)는 한국어.

## Review Focus

1. **Caddy 뒤의 실제 IP** — 신뢰하지 않는 출처가 보낸 `X-Forwarded-For`로 IP를 위조해 차단을 피하거나 남을 차단시킬 수 없어야 한다. → Task 6 테스트 `spoofed forwarded header from untrusted source is ignored`.
2. **계정 존재 여부 노출** — 잠금 해제 코드 요청에서 없는 이메일과 있는 이메일의 응답이 같아야 한다. → Task 4 테스트 `unlock request responds identically for unknown email`.
3. **관리자 본인 잠김** — 관리자가 로그인 실패를 반복해도 잠기지 않고, IP 차단에 걸려도 디스코드의 서명 링크로 풀 수 있어야 한다. → Task 4 `admin is never locked`, Task 6 `signed unblock link removes block`.
4. **가림 처리 누락** — 감지 이슈 payload에 이메일, IPv4/IPv6, Bearer/토큰 형태 문자열, `.env` 값이 남지 않아야 한다. → Task 7 `masker removes emails ips and tokens`.
5. **해제 코드 대입** — 해제 코드를 5회 틀리면 코드가 무효화되고, 15분 뒤 만료되어야 한다. → Task 4 `unlock code invalidated after 5 wrong attempts`, `expired unlock code is rejected`.

---

## 파일 구조

```
/ (저장소 루트 = 라라벨 앱)
├─ app/
│  ├─ Http/Controllers/Auth/{RegisterController,LoginController}.php
│  ├─ Http/Controllers/{PostController,UnlockController,OpsUnblockController}.php
│  ├─ Http/Middleware/BlockSuspiciousIps.php
│  ├─ Models/{User,Post,UnlockCode,BlockedIp}.php
│  ├─ Security/
│  │  ├─ LoginLockout.php          # 로그인 실패 집계·계정 잠금
│  │  ├─ SpamGuard.php             # 글 폭주·반복 감지
│  │  ├─ IpBlocker.php             # 탐침 경로·과도 요청 감지, 차단 관리
│  │  ├─ UnlockCodes.php           # 이메일 해제 코드 발급·검증
│  │  ├─ ErrorSpikeMonitor.php     # 예외 집계·급증 판단
│  │  ├─ IncidentReporter.php      # 깃헙 incident 이슈 생성
│  │  └─ Masker.php                # 개인정보·비밀값 가림
│  ├─ Ops/
│  │  ├─ OpsNotifier.php           # 인터페이스
│  │  ├─ OpsEvent.php              # 값 객체
│  │  ├─ DiscordWebhookNotifier.php
│  │  ├─ SlackWebhookNotifier.php
│  │  └─ LogNotifier.php
│  ├─ Console/Commands/CheckDisk.php
│  └─ Mail/UnlockCodeMail.php
├─ config/{security.php,ops.php}
├─ resources/views/...             # 최소 Blade 화면
├─ tests/Feature/...  tests/Unit/...
├─ docker/{Dockerfile,Caddyfile.app,compose.yml,env.example}
├─ deploy/{deploy.sh,caddy-snippet.caddy,server-setup.md}
├─ .github/
│  ├─ actions/notify/action.yml
│  ├─ ISSUE_TEMPLATE/task.yml
│  ├─ CODEOWNERS
│  ├─ dependabot.yml
│  ├─ risk-paths.txt
│  └─ workflows/{checks-php.yml,ci.yml,risk-and-review.yml,claude.yml,incident.yml,deploy.yml,uptime.yml,token-expiry.yml,issue-notify.yml}
└─ README.md
```

---

## 마일스톤 A — 앱

### Task 1: 저장소와 라라벨 골격

**Files:**
- Create: 라라벨 기본 구조 전체, `phpstan.neon`, `.gitignore` 보강
- Test: `tests/Feature/HealthTest.php`

**Interfaces:**
- Produces: `GET /up` (라라벨 기본 헬스 체크, 200), Pest 실행 환경(SQLite in-memory)

- [ ] **Step 1:** `D:\ClaudeProjects\_acp_tmp`에 `composer create-project laravel/laravel _acp_tmp` 후 내용 전체를 `D:\ClaudeProjects\ai-collab-pipeline\`로 옮긴다(기존 `docs/` 유지). 임시 폴더 삭제.
- [ ] **Step 2:** `composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan` 후 `./vendor/bin/pest --init`. `phpstan.neon`은 Larastan 확장 포함, level 6, paths `app`.
- [ ] **Step 3: 실패 테스트** `tests/Feature/HealthTest.php`

```php
it('reports healthy', function () {
    $this->get('/up')->assertOk();
});
```

- [ ] **Step 4:** `phpunit.xml`에서 `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` 확인 후 `./vendor/bin/pest` → PASS. `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse` → 오류 0.
- [ ] **Step 5:** `git init -b main`, `.env`가 무시되는지 확인(`git check-ignore .env` 출력 있음), 커밋 `chore: laravel skeleton with pest, larastan, pint`.

### Task 2: 게시판(가입·로그인·글쓰기)과 이메일 인증

**Files:**
- Create: `app/Http/Controllers/Auth/RegisterController.php`, `LoginController.php`, `app/Http/Controllers/PostController.php`, `app/Models/Post.php`, 마이그레이션 `posts`, `users`에 컬럼 추가, `resources/views/{layout,auth/login,auth/register,posts/index,posts/create}.blade.php`
- Test: `tests/Feature/BoardTest.php`

**Interfaces:**
- Produces:
  - `users` 컬럼: `is_admin bool default false`, `locked_at timestamp null`, `posting_restricted_at timestamp null`
  - `posts`: `id, user_id, title string(200), body text, body_hash char(64), hidden_at timestamp null, timestamps`
  - `User implements MustVerifyEmail`
  - 라우트 이름: `register`, `login`, `logout`, `posts.index`(GET `/`), `posts.create`, `posts.store`(POST `/posts`, `auth`+`verified`)
  - `Post::scopeVisible()` → `hidden_at IS NULL`

- [ ] **Step 1: 실패 테스트**

```php
it('lists only visible posts', function () {
    Post::factory()->create(['title' => 'shown']);
    Post::factory()->create(['title' => 'hidden', 'hidden_at' => now()]);
    $this->get('/')->assertSee('shown')->assertDontSee('hidden');
});
it('requires verified email to post', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post('/posts', ['title' => 't', 'body' => 'b'])
        ->assertRedirect(route('verification.notice'));
});
it('verified user can post', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/posts', ['title' => 't', 'body' => 'b'])->assertRedirect('/');
    expect(Post::first()->body_hash)->toBe(hash('sha256', 'b'));
});
it('registers and logs in', function () {
    $this->post('/register', ['name'=>'a','email'=>'a@x.test','password'=>'Passw0rd!x','password_confirmation'=>'Passw0rd!x'])->assertRedirect();
    $this->post('/logout');
    $this->post('/login', ['email'=>'a@x.test','password'=>'Passw0rd!x'])->assertRedirect('/');
});
```

- [ ] **Step 2:** `./vendor/bin/pest tests/Feature/BoardTest.php` → FAIL.
- [ ] **Step 3:** 구현. 인증은 스타터킷 없이 컨트롤러 직접 작성(`Auth::attempt`, 세션 재생성). 이메일 인증은 라라벨 기본 `verification.*` 라우트 사용. 화면은 스타일 최소.
- [ ] **Step 4:** 테스트 PASS, pint/phpstan 통과.
- [ ] **Step 5:** 커밋 `feat: minimal board with auth and email verification`.

### Task 3: 교체 가능한 운영 알림(OpsNotifier)

**Files:**
- Create: `app/Ops/{OpsNotifier,OpsEvent,DiscordWebhookNotifier,SlackWebhookNotifier,LogNotifier}.php`, `config/ops.php`, `app/Providers/AppServiceProvider.php` 바인딩
- Test: `tests/Unit/OpsNotifierTest.php`

**Interfaces:**
- Produces:
  - `final class OpsEvent { __construct(public string $title, public string $level /* info|warn|critical */, public string $summary, public ?string $url = null, public array $fields = []) }`
  - `interface OpsNotifier { public function send(OpsEvent $event): void; }`
  - `config('ops.notifier')` = `env('OPS_NOTIFIER','log')` ∈ `discord|slack|log`; `config('ops.discord_webhook')`, `config('ops.slack_webhook')`, `config('ops.app_env_label')`(예: `staging`)
  - 컨테이너 바인딩: `OpsNotifier` → 설정값에 맞는 구현
  - 테스트 헬퍼(`tests/Pest.php`): `fakeOpsNotifier(): RecordingOpsNotifier` — `tests/Support/RecordingOpsNotifier.php`(`public array $events`에 받은 `OpsEvent`를 쌓음)를 컨테이너에 바인딩하고 반환. 이후 모든 Task의 테스트가 사용.

- [ ] **Step 1: 실패 테스트**

```php
it('posts discord embed with link', function () {
    Http::fake();
    config(['ops.notifier' => 'discord', 'ops.discord_webhook' => 'https://discord.test/hook', 'ops.app_env_label' => 'staging']);
    app(OpsNotifier::class)->send(new OpsEvent('계정 잠금', 'warn', 'user#3 잠금', 'https://app.test/x'));
    Http::assertSent(fn ($r) => $r->url() === 'https://discord.test/hook'
        && str_contains($r['embeds'][0]['title'], '[staging] 계정 잠금')
        && $r['embeds'][0]['url'] === 'https://app.test/x');
});
it('posts slack blocks when provider is slack', function () {
    Http::fake();
    config(['ops.notifier' => 'slack', 'ops.slack_webhook' => 'https://slack.test/hook']);
    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));
    Http::assertSent(fn ($r) => $r->url() === 'https://slack.test/hook' && isset($r['blocks']));
});
it('never throws when webhook fails', function () {
    Http::fake(['*' => Http::response('', 500)]);
    config(['ops.notifier' => 'discord', 'ops.discord_webhook' => 'https://discord.test/hook']);
    app(OpsNotifier::class)->send(new OpsEvent('t', 'info', 's'));
    expect(true)->toBeTrue();
});
```

- [ ] **Step 2:** FAIL 확인.
- [ ] **Step 3:** 구현. 디스코드 embed 색: info `0x3498db`, warn `0xf1c40f`, critical `0xe74c3c`. 발송 실패는 `Log::warning`만 남기고 삼킨다(알림 실패가 요청 실패로 번지지 않게). 타임아웃 5초.
- [ ] **Step 4:** PASS. **Step 5:** 커밋 `feat: swappable ops notifier (discord, slack, log)`.

### Task 4: 로그인 실패 계정 잠금과 이메일 코드 해제

**Files:**
- Create: `app/Security/{LoginLockout,UnlockCodes}.php`, `app/Models/UnlockCode.php`, 마이그레이션 `unlock_codes`, `app/Http/Controllers/UnlockController.php`, `app/Mail/UnlockCodeMail.php`, `resources/views/unlock/{request,verify}.blade.php`, `config/security.php`
- Modify: `LoginController` (잠금 확인·실패 기록)
- Test: `tests/Feature/LoginLockoutTest.php`, `tests/Feature/UnlockTest.php`

**Interfaces:**
- Consumes: `OpsNotifier`, `OpsEvent`(Task 3), `users.locked_at`, `users.posting_restricted_at`, `users.is_admin`(Task 2)
- Produces:
  - `config('security.login')` = `['max_failures' => 5, 'window_minutes' => 15]`
  - `config('security.unlock')` = `['code_ttl_minutes' => 15, 'max_attempts' => 5]`
  - `LoginLockout::recordFailure(string $email): void` — 실패 횟수는 `RateLimiter` 키 `login-fail:{sha1(lower(email))}`; 임계 도달 시 해당 사용자(존재하고 비관리자) `locked_at=now()` + `OpsEvent('계정 잠금','warn',…)`
  - `LoginLockout::clear(string $email): void`
  - `UnlockCodes::issue(User $user, string $purpose /* 'login'|'posting' */): void` — 6자리 숫자, `unlock_codes(user_id, purpose, code_hash, attempts, expires_at, used_at)`, 이전 미사용 코드 무효화, 메일 발송
  - `UnlockCodes::verify(User $user, string $purpose, string $code): bool` — 성공 시 purpose에 따라 `locked_at` 또는 `posting_restricted_at`을 null로, 실패 시 attempts++ 후 `max_attempts` 도달 시 `used_at=now()`로 무효화
  - 라우트: `unlock.request` GET/POST `/unlock`(입력: email, purpose), `unlock.verify` GET/POST `/unlock/verify`(입력: email, purpose, code). `throttle:10,1` 적용.
  - `UnlockCodeMail` 생성자 `(public string $code, public string $purpose)`.
  - 테스트 헬퍼(`tests/Pest.php`): `issueKnownCode(User $u, string $purpose): string` — `Mail::fake()` 후 `UnlockCodes::issue` 호출, 보낸 `UnlockCodeMail::$code`를 반환.

- [ ] **Step 1: 실패 테스트**

```php
it('locks account after 5 failures and notifies', function () {
    $ops = fakeOpsNotifier(); // tests/Pest.php 헬퍼: 컨테이너에 기록용 OpsNotifier 바인딩
    $u = User::factory()->create(['password' => bcrypt('right-pass')]);
    foreach (range(1,5) as $_) $this->post('/login', ['email'=>$u->email,'password'=>'wrong']);
    expect($u->fresh()->locked_at)->not->toBeNull();
    expect($ops->events)->toHaveCount(1)->and($ops->events[0]->title)->toBe('계정 잠금');
    $this->post('/login', ['email'=>$u->email,'password'=>'right-pass'])->assertSessionHasErrors('email');
});
it('admin is never locked', function () {
    $u = User::factory()->create(['is_admin' => true]);
    foreach (range(1,10) as $_) $this->post('/login', ['email'=>$u->email,'password'=>'wrong']);
    expect($u->fresh()->locked_at)->toBeNull();
});
it('unlock request responds identically for unknown email', function () {
    Mail::fake();
    $u = User::factory()->create(['locked_at' => now()]);
    $a = $this->post('/unlock', ['email'=>$u->email,'purpose'=>'login']);
    $b = $this->post('/unlock', ['email'=>'nobody@x.test','purpose'=>'login']);
    expect($a->status())->toBe($b->status())->and($a->headers->get('Location'))->toBe($b->headers->get('Location'));
    Mail::assertSent(UnlockCodeMail::class, 1);
});
it('correct code unlocks', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login'); // 헬퍼: UnlockCodes 발급 후 평문 코드 반환(테스트용 Mail::fake로 캡처)
    $this->post('/unlock/verify', ['email'=>$u->email,'purpose'=>'login','code'=>$code]);
    expect($u->fresh()->locked_at)->toBeNull();
});
it('unlock code invalidated after 5 wrong attempts', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login');
    foreach (range(1,5) as $_) $this->post('/unlock/verify', ['email'=>$u->email,'purpose'=>'login','code'=>'000000' === $code ? '111111' : '000000']);
    $this->post('/unlock/verify', ['email'=>$u->email,'purpose'=>'login','code'=>$code]);
    expect($u->fresh()->locked_at)->not->toBeNull();
});
it('expired unlock code is rejected', function () {
    $u = User::factory()->create(['locked_at' => now()]);
    $code = issueKnownCode($u, 'login');
    $this->travel(16)->minutes();
    $this->post('/unlock/verify', ['email'=>$u->email,'purpose'=>'login','code'=>$code]);
    expect($u->fresh()->locked_at)->not->toBeNull();
});
```

- [ ] **Step 2:** FAIL 확인.
- [ ] **Step 3:** 구현. 잠긴 계정 로그인 시도 응답 메시지는 "계정이 잠겼습니다. 이메일 인증으로 해제하세요" + `unlock.request` 링크. 코드는 `hash_hmac('sha256', $code, config('app.key'))`로 저장, 비교는 `hash_equals`. 해제 성공 시 `LoginLockout::clear`.
- [ ] **Step 4:** PASS. **Step 5:** 커밋 `feat: account lockout with email code unlock`.

### Task 5: 스팸 감지 — 글쓰기 제한과 숨김

**Files:**
- Create: `app/Security/SpamGuard.php`
- Modify: `PostController@store`, `config/security.php`
- Test: `tests/Feature/SpamGuardTest.php`

**Interfaces:**
- Consumes: `UnlockCodes`(purpose `posting`), `OpsNotifier`, `Post.body_hash`, `users.posting_restricted_at`
- Produces:
  - `config('security.spam')` = `['max_posts' => 5, 'window_minutes' => 10, 'max_same_body' => 3, 'max_same_link' => 3]`
  - `SpamGuard::inspect(User $user, Post $justCreated): bool` — 창 안의 글 수 > `max_posts`, 같은 `body_hash` ≥ `max_same_body`, 같은 URL(본문에서 추출) ≥ `max_same_link` 중 하나면 true. true일 때: `posting_restricted_at=now()`, 창 안의 해당 사용자 글 전부 `hidden_at=now()`, `OpsEvent('스팸 의심 — 글쓰기 제한','warn',…)`. 관리자는 항상 false.
  - 제한된 사용자가 `posts.store` 요청 시 403 대신 `unlock.request?purpose=posting`으로 리다이렉트.

- [ ] **Step 1: 실패 테스트**

```php
it('restricts and hides on burst', function () {
    $ops = fakeOpsNotifier();
    $u = User::factory()->create();
    foreach (range(1,6) as $i) $this->actingAs($u)->post('/posts', ['title'=>"t$i",'body'=>"b$i"]);
    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
    expect(Post::visible()->where('user_id',$u->id)->count())->toBe(0);
    expect($ops->events)->toHaveCount(1);
});
it('restricts on repeated identical body', function () {
    $u = User::factory()->create();
    foreach (range(1,3) as $i) $this->actingAs($u)->post('/posts', ['title'=>"t$i",'body'=>'same spam']);
    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
});
it('restricts on repeated link', function () {
    $u = User::factory()->create();
    foreach (range(1,3) as $i) $this->actingAs($u)->post('/posts', ['title'=>"t$i",'body'=>"buy $i https://spam.test/x"]);
    expect($u->fresh()->posting_restricted_at)->not->toBeNull();
});
it('restricted user is sent to posting unlock', function () {
    $u = User::factory()->create(['posting_restricted_at' => now()]);
    $this->actingAs($u)->post('/posts', ['title'=>'t','body'=>'b'])->assertRedirectContains('/unlock?purpose=posting');
});
it('admin is never restricted', function () {
    $u = User::factory()->create(['is_admin' => true]);
    foreach (range(1,8) as $i) $this->actingAs($u)->post('/posts', ['title'=>"t$i",'body'=>'same']);
    expect($u->fresh()->posting_restricted_at)->toBeNull();
});
```

- [ ] **Step 2:** FAIL. **Step 3:** 구현(글 삭제 금지, 숨김만). **Step 4:** PASS. **Step 5:** 커밋 `feat: spam guard restricts posting and hides burst`.

### Task 6: IP 차단 미들웨어와 신뢰 프록시

**Files:**
- Create: `app/Security/IpBlocker.php`, `app/Models/BlockedIp.php`, 마이그레이션 `blocked_ips`, `app/Http/Middleware/BlockSuspiciousIps.php`, `app/Http/Controllers/OpsUnblockController.php`
- Modify: `bootstrap/app.php`(미들웨어 등록, `trustProxies`), `config/security.php`
- Test: `tests/Feature/IpBlockTest.php`

**Interfaces:**
- Consumes: `OpsNotifier`, `users.is_admin`
- Produces:
  - `config('security.ip')` = `['probe_paths' => ['.env','wp-admin','wp-login.php','phpmyadmin','.git/config','xmlrpc.php'], 'probe_block_minutes' => 60, 'max_requests' => 300, 'request_window_minutes' => 5, 'flood_block_minutes' => 30, 'trusted_proxies' => env('TRUSTED_PROXIES','')]`
  - `blocked_ips(ip string(45) unique, reason string, expires_at timestamp, timestamps)`
  - `IpBlocker::isBlocked(string $ip): bool`, `IpBlocker::block(string $ip, string $reason, int $minutes): BlockedIp`(새로 막을 때만 알림), `IpBlocker::unblock(string $ip): void`
  - 서명 라우트 `ops.unblock` GET `/ops/unblock/{ip}` — `URL::temporarySignedRoute(..., now()->addHours(24))`, 차단 알림 `OpsEvent.url`에 이 링크를 넣는다.
  - 미들웨어는 `web` 그룹에서 세션 시작 **뒤**에 둔다(로그인한 관리자 판별). 순서: 관리자면 통과 → 차단 중이면 403 → 탐침 경로면 차단 → 요청 수 초과면 차단.
  - 신뢰 프록시: `TRUSTED_PROXIES`에 `acp-edge` 네트워크 대역(CIDR)만 지정. 빈 값이면 아무도 신뢰하지 않음.

- [ ] **Step 1: 실패 테스트**

```php
it('blocks probe path and notifies with signed unblock link', function () {
    $ops = fakeOpsNotifier();
    $this->get('/.env', ['REMOTE_ADDR' => '203.0.113.9'])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.9'])->get('/')->assertForbidden();
    expect($ops->events[0]->url)->toContain('/ops/unblock/203.0.113.9')->toContain('signature=');
});
it('signed unblock link removes block', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);
    $url = URL::temporarySignedRoute('ops.unblock', now()->addHour(), ['ip' => '203.0.113.9']);
    $this->withServerVariables(['REMOTE_ADDR'=>'198.51.100.1'])->get($url)->assertOk();
    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});
it('unsigned unblock is rejected', function () {
    $this->get('/ops/unblock/203.0.113.9')->assertForbidden();
});
it('block expires', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 30);
    $this->travel(31)->minutes();
    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeFalse();
});
it('logged-in admin passes even from blocked ip', function () {
    app(IpBlocker::class)->block('203.0.113.9', 'test', 60);
    $this->actingAs(User::factory()->create(['is_admin'=>true]))
        ->withServerVariables(['REMOTE_ADDR'=>'203.0.113.9'])->get('/')->assertOk();
});
it('spoofed forwarded header from untrusted source is ignored', function () {
    config(['security.ip.trusted_proxies' => '172.30.0.0/16']);
    $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.9'])
        ->get('/.env', ['X-Forwarded-For' => '198.51.100.7']);
    expect(app(IpBlocker::class)->isBlocked('203.0.113.9'))->toBeTrue()
        ->and(app(IpBlocker::class)->isBlocked('198.51.100.7'))->toBeFalse();
});
it('forwarded header from trusted proxy is used', function () {
    config(['security.ip.trusted_proxies' => '172.30.0.0/16']);
    $this->withServerVariables(['REMOTE_ADDR'=>'172.30.0.5'])
        ->get('/.env', ['X-Forwarded-For' => '198.51.100.7']);
    expect(app(IpBlocker::class)->isBlocked('198.51.100.7'))->toBeTrue()
        ->and(app(IpBlocker::class)->isBlocked('172.30.0.5'))->toBeFalse();
});
```

- [ ] **Step 2:** FAIL. **Step 3:** 구현. 요청 수 집계는 `RateLimiter` 키 `ip-req:{ip}`. `trusted_proxies` 설정은 런타임에서 읽도록 `TrustProxies` 미들웨어를 설정값으로 구성(테스트에서 `config()`로 바꿀 수 있어야 함). **Step 4:** PASS. **Step 5:** 커밋 `feat: app-level ip blocking with signed unblock and trusted proxy`.

### Task 7: 에러 급증 감지, 가림 처리, incident 이슈 생성 · 디스크 점검

**Files:**
- Create: `app/Security/{ErrorSpikeMonitor,IncidentReporter,Masker}.php`, `app/Console/Commands/CheckDisk.php`
- Modify: `bootstrap/app.php`(`withExceptions(...)->report(...)`), `routes/console.php`(스케줄), `config/security.php`, `config/ops.php`
- Test: `tests/Unit/MaskerTest.php`, `tests/Feature/ErrorSpikeTest.php`, `tests/Feature/CheckDiskTest.php`

**Interfaces:**
- Consumes: `OpsNotifier`
- Produces:
  - `config('security.errors')` = `['threshold' => 10, 'window_minutes' => 5, 'dedupe_hours' => 24, 'daily_issue_cap' => 5]`
  - `config('ops.github')` = `['repo' => env('GITHUB_REPO'), 'token' => env('GITHUB_INCIDENT_TOKEN')]` — 토큰은 이 저장소 **Issues: write 전용** fine-grained PAT
  - `Masker::mask(string $text): string` — 이메일 → `[email]`, IPv4/IPv6 → `[ip]`, `Bearer \S+`·`(api|secret|token|key|password)\s*[=:]\s*\S+` → `[secret]`, 32자 이상 hex/base64 덩어리 → `[secret]`
  - `ErrorSpikeMonitor::record(Throwable $e): void` — 지문 `sha1(class|file|line)`, 캐시 카운터. 임계 도달 시 1회만: `OpsEvent('에러 급증','critical',…)` + `IncidentReporter::report(array $payload)`
  - `IncidentReporter::report(array $payload): ?string`(생성된 이슈 URL) — 같은 지문은 `dedupe_hours` 내 재생성 안 함, 하루 `daily_issue_cap` 초과 시 생성 안 하고 알림만. 이슈: 제목 `[incident] {class} at {file}:{line}`, 라벨 `incident`, 본문은 가림 처리된 메시지·위치·횟수·환경·첫/마지막 발생 시각과 마커 `<!-- acp-incident:{fingerprint} -->`. 원본 스택트레이스·요청 데이터는 넣지 않는다.
  - `php artisan ops:check-disk` — `/` 여유 공간 10% 미만이면 `OpsEvent('디스크 부족','critical',…)`. 스케줄 매시.

- [ ] **Step 1: 실패 테스트**

```php
it('masker removes emails ips and tokens', function () {
    $out = Masker::mask('user a.b@x.com from 203.0.113.9 and 2001:db8::1 Bearer abc.def password=hunter2 key=' . str_repeat('a1', 20));
    expect($out)->not->toContain('a.b@x.com')->not->toContain('203.0.113.9')->not->toContain('2001:db8::1')
        ->not->toContain('abc.def')->not->toContain('hunter2')->not->toContain(str_repeat('a1', 20));
});
it('creates one incident issue after threshold', function () {
    Http::fake(['api.github.com/*' => Http::response(['html_url' => 'https://github.com/o/r/issues/9'], 201)]);
    config(['ops.github.repo' => 'o/r', 'ops.github.token' => 't']);
    $e = new RuntimeException('boom for a.b@x.com');
    foreach (range(1, 12) as $_) app(ErrorSpikeMonitor::class)->record($e);
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['body'], '[email]') && !str_contains($r['body'], 'a.b@x.com')
        && in_array('incident', $r['labels']));
});
it('does not recreate within dedupe window', function () { /* 같은 예외로 임계 두 번 → 이슈 1개 */ });
it('respects daily issue cap', function () { /* 서로 다른 지문 6개 → 이슈 5개, 6번째는 알림만 */ });
it('warns when disk free below 10 percent', function () { /* 디스크 조회를 주입 가능한 함수로 분리해 9% 반환 → OpsEvent 1개 */ });
```

- [ ] **Step 2:** FAIL. **Step 3:** 구현. 리포팅 경로에서 발생한 예외는 삼킨다(무한 루프 방지). 디스크 조회는 `disk_free_space`/`disk_total_space`를 감싼 `DiskUsage` 콜러블을 생성자 주입. **Step 4:** PASS. **Step 5:** 커밋 `feat: error spike incidents with masking and disk check`.

### Task 8: 도커 이미지와 compose

**Files:**
- Create: `docker/Dockerfile`, `docker/Caddyfile.app`, `docker/compose.yml`, `docker/env.example`, `.dockerignore`
- Test: 로컬 스모크(아래 명령)

**Interfaces:**
- Produces:
  - 이미지 `ghcr.io/<owner>/ai-collab-pipeline:<git-sha>` — 베이스 `dunglas/frankenphp:1-php8.4`, 확장 `pdo_mysql, intl, zip, opcache, pcntl`, `composer install --no-dev --optimize-autoloader`, 포트 8080, 엔트리포인트에서 `php artisan migrate --force`(웹 서비스에서만, `RUN_MIGRATIONS=1`일 때), `config:cache`/`route:cache`/`view:cache`
  - compose 서비스: `web`(이미지, `acp-edge`와 내부 네트워크 둘 다), `worker`(`php artisan queue:work`), `scheduler`(`php artisan schedule:work`), `db`(`mysql:8.4`, 볼륨 `db-data`, 내부 네트워크만). 이미지 태그는 `${APP_IMAGE}`. 외부 네트워크 `acp-edge`(external: true). 웹 컨테이너 별칭 `acp-${APP_ENV_LABEL}-web`.
  - 환경별 실행: `docker compose -p acp-staging --env-file /opt/acp/staging/.env -f compose.yml up -d` (운영은 `acp-prod`, `/opt/acp/prod/.env`)

- [ ] **Step 1:** 로컬에서 `docker network create acp-edge`(없을 때), `docker build -f docker/Dockerfile -t acp:local .`
- [ ] **Step 2:** `APP_IMAGE=acp:local docker compose -p acp-local --env-file docker/env.example -f docker/compose.yml up -d` 후 `docker compose -p acp-local exec web curl -fsS http://localhost:8080/up` → 200.
- [ ] **Step 3:** `docker compose -p acp-local down -v`. 이미지 크기·로그 확인.
- [ ] **Step 4:** 커밋 `build: frankenphp image and compose for staging/prod`.

---

## 마일스톤 B — 깃헙 파이프라인

> 워크플로 검증은 `actionlint`(로컬: `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest`)로 문법을 확인하고, 실제 동작은 Task 15의 저장소 설정 뒤 Task 17에서 확인한다.

### Task 9: 알림 액션과 PHP 검사(교체 가능) + CI

**Files:**
- Create: `.github/actions/notify/action.yml`, `.github/workflows/checks-php.yml`, `.github/workflows/ci.yml`, `.github/workflows/issue-notify.yml`

**Interfaces:**
- Produces:
  - 복합 액션 `./.github/actions/notify` 입력: `provider`(기본 `${{ vars.NOTIFY_PROVIDER || 'discord' }}`를 호출부에서 전달), `channel`(`dev`|`ops`), `title`, `level`(`info`|`warn`|`critical`), `summary`, `url`. 웹훅은 env `DISCORD_WEBHOOK_DEV`, `DISCORD_WEBHOOK_OPS`, `SLACK_WEBHOOK_DEV`, `SLACK_WEBHOOK_OPS`에서 고른다. `curl` + `jq`로 앱의 OpsNotifier와 같은 모양(디스코드 embed / 슬랙 blocks) 전송. 웹훅이 비어 있으면 경고만 하고 성공 처리.
  - 재사용 워크플로 `checks-php.yml`(`on: workflow_call`) 잡: `pint --test`, `phpstan analyse`, `pest`, `composer audit`. **언어 교체 시 이 파일만 바꾼다.**
  - `ci.yml`(`on: pull_request`) 잡 이름(브랜치 보호 필수 검사로 등록할 이름): `php-checks`(→ `checks-php.yml` 호출), `secret-scan`(gitleaks, `fetch-depth: 0`). 마지막 잡 `report`(needs 둘 다, `if: always()`): 결과 요약을 dev 채널로 알림, URL은 PR.
  - `issue-notify.yml`(`on: issues: [opened]`): dev 채널 "이슈 접수", URL은 이슈.
  - 모든 워크플로 기본 `permissions: contents: read`, 필요한 잡에서만 확장.

- [ ] **Step 1:** 파일 작성. 전송 로직은 `.github/actions/notify/notify.sh`로 분리하고, `NOTIFY_DRY_RUN=1`이면 전송 대신 payload를 stdout에 출력한다.
- [ ] **Step 2:** actionlint 통과.
- [ ] **Step 3:** `NOTIFY_DRY_RUN=1 PROVIDER=discord TITLE=t LEVEL=warn SUMMARY=s URL=https://x.test bash .github/actions/notify/notify.sh | jq -e '.embeds[0].url == "https://x.test"'` → 종료 0. `PROVIDER=slack`으로 `jq -e '.blocks | length > 0'` → 종료 0.
- [ ] **Step 4:** 커밋 `ci: php checks reusable workflow, secret scan, swappable notify action`.

### Task 10: 위험도 분류 + PR 첫 AI 리뷰 + CODEOWNERS

**Files:**
- Create: `.github/risk-paths.txt`, `.github/scripts/classify-risk.sh`, `.github/workflows/risk-and-review.yml`, `.github/CODEOWNERS`
- Test: `.github/scripts/test-classify-risk.sh`

**Interfaces:**
- Produces:
  - `risk-paths.txt`(한 줄에 glob 하나): `app/Http/Controllers/Auth/**`, `app/Security/**`, `app/Http/Middleware/**`, `database/migrations/**`, `.github/**`, `docker/**`, `deploy/**`, `config/**`, `composer.json`, `composer.lock`, `bootstrap/app.php`, `.env*`
  - `classify-risk.sh <changed-files-file>` → stdout 첫 줄 `high` 또는 `low`, 이후 줄은 매칭된 파일 목록. 종료 코드 0.
  - `risk-and-review.yml`(`on: pull_request: [opened, synchronize, reopened]`):
    - 잡 `risk`: 변경 파일로 분류 → 라벨 `risk:high`/`risk:low` 설정(반대 라벨 제거). high면 ops가 아닌 dev 채널에 `level: warn` "사람 확인 필요" + 위험 파일 목록 알림.
    - 잡 `ai-review`(needs `risk`, **`if: github.event.action == 'opened'`**): `anthropics/claude-code-action@v1`, `claude_code_oauth_token`, GitHub App 토큰. 프롬프트: 한국어로 리뷰 의견을 남기고, 라벨이 `risk:high`면 PR 본문 맨 위에 `## 고위험 변경 요약`(무엇을/왜/영향 범위 + 위험 파일 목록) 섹션을 추가. 코드 수정·커밋 금지(`--allowedTools`로 편집 도구 제외).
  - `CODEOWNERS`: `risk-paths.txt`의 각 경로 → `@<owner>`

- [ ] **Step 1: 실패 테스트** `test-classify-risk.sh`: 입력 `app/Models/Post.php` → `low`; 입력 `app/Models/Post.php\napp/Security/Masker.php` → 첫 줄 `high`, 둘째 줄 `app/Security/Masker.php`; 입력 `.github/workflows/ci.yml` → `high`; 빈 입력 → `low`.
- [ ] **Step 2:** `bash .github/scripts/test-classify-risk.sh` → FAIL. **Step 3:** 구현(bash `extglob`/`globstar`로 매칭). **Step 4:** PASS + actionlint. **Step 5:** 커밋 `ci: risk labeling, first-open ai review, codeowners`.

### Task 11: `@claude` 작업자 워크플로와 이슈 양식

**Files:**
- Create: `.github/workflows/claude.yml`, `.github/ISSUE_TEMPLATE/task.yml`, `.github/ISSUE_TEMPLATE/config.yml`, `CLAUDE.md`(AI 작업 규칙)

**Interfaces:**
- Consumes: notify 액션(Task 9), 위험도 라벨(Task 10)
- Produces:
  - `claude.yml` 트리거: `issue_comment: [created]`, `pull_request_review_comment: [created]`, `pull_request_review: [submitted]`, `issues: [opened, assigned]`. 조건: 본문에 `@claude` 포함 **그리고** `author_association ∈ {OWNER, COLLABORATOR}`.
  - 잡은 `environment`를 지정하지 않는다(배포 비밀키 접근 불가). 사용하는 비밀값: `CLAUDE_CODE_OAUTH_TOKEN`, `ACP_APP_ID`, `ACP_APP_PRIVATE_KEY`, 디스코드 웹훅만.
  - `actions/create-github-app-token`으로 App 토큰 발급 → `claude-code-action`의 `github_token`. AI가 PR을 열 수 있도록 설정(작업 브랜치 접두사 `claude/`).
  - 시작 시 dev 채널 "AI 작업 시작"(URL: 이슈/PR), 종료 시 "AI 작업 완료" 또는 실패 알림.
  - `task.yml` 이슈 양식 필드(한국어): 무엇을(필수), 어디서(필수), 기대 결과(필수), 완료 체크리스트(필수, 체크박스 마크다운 안내), 참고 스크린샷(선택). 본문 끝에 기본값 `@claude` 포함 체크박스 "AI에게 바로 작업 맡기기"(체크 시 본문에 `@claude` 문구 포함되게 안내 텍스트 구성).
  - `CLAUDE.md`: 테스트 먼저 작성, `./vendor/bin/pest`·`pint`·`phpstan` 통과 후 PR, PR 본문에 "무엇을/왜/테스트 방법" 한국어로, `.github/`·`deploy/` 수정은 이슈에서 명시적으로 요청된 경우에만, 비밀값 출력 금지.

- [ ] **Step 1:** 작성. **Step 2:** actionlint 통과, 이슈 양식 YAML이 GitHub 스키마에 맞는지 `check-jsonschema --builtin-schema vendor.github-issue-forms .github/ISSUE_TEMPLATE/task.yml`(pipx/uvx로 실행) 통과. **Step 3:** 커밋 `ci: claude worker workflow, issue form, ai rules`.

### Task 12: incident 이슈 → AI 분석·수정 PR

**Files:**
- Create: `.github/workflows/incident.yml`

**Interfaces:**
- Consumes: 앱이 만든 `incident` 라벨 이슈와 마커 `<!-- acp-incident:... -->`(Task 7)
- Produces:
  - 트리거 `issues: [opened, labeled]`, 조건: 라벨 `incident` 포함 **그리고** 본문에 `acp-incident:` 마커 **그리고** 작성자가 저장소 소유자(PAT 소유자) — `github.event.issue.author_association == 'OWNER'`.
  - `claude-code-action`을 `prompt` 입력으로 실행: 이슈의 가림 처리된 정보와 코드를 바탕으로 원인 분석을 이슈 댓글로 남기고, 수정 가능하면 테스트를 포함한 수정 PR을 연다(App 토큰). 운영 로그나 서버에는 접근하지 않는다.
  - ops 채널 알림 "AI 복구 작업 시작/완료" + 이슈·PR 링크.

- [ ] **Step 1:** 작성. **Step 2:** actionlint. **Step 3:** 커밋 `ci: incident issues trigger ai analysis and fix pr`.

### Task 13: 빌드·배포 워크플로와 서버 배포 스크립트

**Files:**
- Create: `.github/workflows/deploy.yml`, `deploy/deploy.sh`, `deploy/test-deploy-args.sh`

**Interfaces:**
- Consumes: 이미지 정의(Task 8), notify 액션
- Produces:
  - `deploy.sh` — 서버의 `acp-deploy` 계정 `authorized_keys`에 `command="/opt/acp/bin/deploy.sh",no-port-forwarding,no-agent-forwarding,no-pty,no-X11-forwarding`로 강제 실행. 입력은 `SSH_ORIGINAL_COMMAND`에서만 받으며 형식 `deploy <staging|prod> <40자 hex sha>`만 허용, 그 외는 종료 코드 2. 동작: `APP_IMAGE=ghcr.io/<owner>/ai-collab-pipeline:<sha>`로 `docker compose -p acp-<env> --env-file /opt/acp/<env>/.env -f /opt/acp/compose.yml pull && up -d`, 이후 `docker compose ... exec -T web curl -fsS http://localhost:8080/up` 실패 시 종료 코드 3. 직전 sha를 `/opt/acp/<env>/last_sha`에 기록.
  - `deploy.yml`(`on: push: branches: [main]`):
    - 잡 `build`: GHCR 로그인(`GITHUB_TOKEN`, `packages: write`), 이미지 빌드·푸시 태그 `${{ github.sha }}`.
    - 잡 `staging`(needs build, `environment: staging`): 비밀값 `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_KNOWN_HOSTS`(환경 비밀값). `ssh acp-deploy@host "deploy staging $SHA"`. 외부에서 `https://<staging 도메인>/up` 확인(변수 `STAGING_URL`). dev 채널 알림(스테이징 주소).
    - 잡 `production-approval-notice`(needs staging): dev 채널 "운영 배포 승인 대기" + 워크플로 실행 링크(`${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}`).
    - 잡 `production`(needs staging, `environment: production` — 필수 승인자 사용자): 운영 비밀값은 이 환경에만. 배포 후 `PROD_URL/up` 확인, 성공/실패 알림(실패는 critical, ops 채널).
  - 자동 롤백은 하지 않는다. 실패 알림에 `last_sha`로 되돌리는 방법(이전 실행의 "Re-run" 안내)을 적는다.

- [ ] **Step 1: 실패 테스트** `deploy/test-deploy-args.sh`: `DEPLOY_DRY_RUN=1 SSH_ORIGINAL_COMMAND='deploy staging <40hex>' deploy.sh` → 0이고 실행할 compose 명령을 출력; `'deploy prod abc'` → 2; `'deploy dev <40hex>'` → 2; `'deploy staging <40hex>; rm -rf /'` → 2; 빈 값 → 2.
- [ ] **Step 2:** FAIL. **Step 3:** 구현(정규식 `^deploy (staging|prod) [0-9a-f]{40}$`, `set -euo pipefail`). **Step 4:** PASS + actionlint. **Step 5:** 커밋 `ci: build to ghcr, staging auto deploy, approved production deploy`.

### Task 14: 정기 점검 — Dependabot, 외부 가동 확인, 토큰 만료 알림

**Files:**
- Create: `.github/dependabot.yml`, `.github/workflows/uptime.yml`, `.github/workflows/token-expiry.yml`, `.github/scripts/days-until.sh`, `.github/scripts/test-days-until.sh`

**Interfaces:**
- Produces:
  - Dependabot: `composer`(루트), `github-actions`, `docker`(`/docker`) 주 1회 월요일 09:00 Asia/Seoul. PR은 위 흐름(검사·위험도·사람 승인)을 그대로 탄다.
  - `uptime.yml`: `schedule: '*/10 * * * *'` + `workflow_dispatch`. 대상 `STAGING_URL`, `PROD_URL`(저장소 변수). 각 `/up` 응답 코드·응답 시간(> 3초면 warn), TLS 만료(`openssl s_client`로 notAfter, 14일 미만 warn). 실패만 ops 채널 알림.
  - `token-expiry.yml`: `schedule: '0 0 * * *'`. `days-until.sh "$CLAUDE_TOKEN_EXPIRES"` 결과가 30 이하이고 0보다 크거나 같으면 dev 채널 warn, 음수면 critical "토큰 만료됨".
  - `days-until.sh YYYY-MM-DD` → 오늘(UTC)부터 남은 일수를 정수로 출력. 형식 오류면 종료 코드 2.

- [ ] **Step 1: 실패 테스트** `test-days-until.sh`: `TODAY=2026-11-26 days-until.sh 2026-12-26` → `30`; `2026-11-25` → `-1`; `bad` → 종료 2. (`TODAY` 환경변수로 오늘 날짜 주입 가능)
- [ ] **Step 2:** FAIL. **Step 3:** 구현. **Step 4:** PASS + actionlint. **Step 5:** 커밋 `ci: dependabot, external uptime and tls check, token expiry reminder`.

---

## 마일스톤 C — 설정과 서버 (사용자와 함께, 매 변경 전 확인)

### Task 15: 깃헙 저장소·앱·비밀값·보호 규칙

**Files:**
- Create: `deploy/github-setup.md`(실제 수행 기록과 사용자용 재현 안내, 한국어)

- [ ] **Step 1:** `gh repo create <owner>/ai-collab-pipeline --public --source . --push`(사용자 확인 후). 
- [ ] **Step 2: 사용자 수행 안내 후 확인** — (a) GitHub App 생성(권한: Contents RW, Pull requests RW, Issues RW, Metadata R; 이 저장소에만 설치) → `ACP_APP_ID`, `ACP_APP_PRIVATE_KEY` 비밀값 (b) Claude GitHub App 설치 (c) `claude setup-token` → `CLAUDE_CODE_OAUTH_TOKEN`, 만료일 → 변수 `CLAUDE_TOKEN_EXPIRES` (d) 디스코드 채널 2개(`#dev-pipeline`, `#ops-alerts`) 웹훅 → `DISCORD_WEBHOOK_DEV`, `DISCORD_WEBHOOK_OPS` (e) 변수 `NOTIFY_PROVIDER=discord`, `STAGING_URL`, `PROD_URL`.
- [ ] **Step 3:** 라벨 생성: `risk:high`(빨강), `risk:low`(초록), `incident`(보라).
- [ ] **Step 4:** Environments: `staging`(보호 없음), `production`(필수 승인자 = 사용자, 배포 브랜치 `main`만). 각 환경 비밀값 `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_KNOWN_HOSTS`는 Task 16 뒤 등록.
- [ ] **Step 5:** `main` 룰셋(gh api): PR 필수, 승인 1, 코드 오너 리뷰 필수, 새 커밋 시 기존 승인 무효화, 필수 검사 `php-checks`·`secret-scan`, 강제 푸시·삭제 금지, 우회 목록 없음.
- [ ] **Step 6: 검증** — `gh api repos/<owner>/ai-collab-pipeline/rulesets` 결과에 위 규칙 존재, `git push origin HEAD:main`(테스트 커밋)이 거부되는지 확인 후 테스트 커밋 정리.

### Task 16: VPS 설정 (SSH, 임시 관리 계정 사용)

**Files:**
- Create: `deploy/caddy-snippet.caddy`, `deploy/server-setup.md`(실제 수행 명령과 되돌리는 방법 기록)

**Interfaces:**
- Produces: 서버 경로 `/opt/acp/{compose.yml,bin/deploy.sh,staging/.env,prod/.env}`, 계정 `acp-deploy`(docker 그룹, 셸 로그인은 강제 명령만), 도커 네트워크 `acp-edge`(서브넷 고정 `172.30.0.0/16` — 다른 네트워크와 겹치면 비어 있는 대역으로 바꾸고 `TRUSTED_PROXIES`에 같은 값 사용)

- [ ] **Step 1:** 이 PC에서 `ssh-keygen -t ed25519 -f ~/.ssh/claude_vps -C claude-setup -N ""` → 공개키를 사용자에게 보여 주고, 사용자가 서버에 임시 계정 `claude-setup`(NOPASSWD sudo) 생성·키 등록. 접속 확인 `ssh -i ~/.ssh/claude_vps claude-setup@<host> 'id'`.
- [ ] **Step 2:** 읽기 전용 재확인: `docker network ls`, `docker network inspect` 로 서브넷 충돌 확인, Caddyfile 위치(`docker inspect webstack-caddy-1`의 마운트) 확인.
- [ ] **Step 3 (사용자 확인 후):** `docker network create --subnet 172.30.0.0/16 acp-edge` → `docker network connect acp-edge webstack-caddy-1`(재시작 없음).
- [ ] **Step 4:** `acp-deploy` 계정, `/opt/acp` 구조, `deploy.sh`(Task 13) 설치, `authorized_keys` 강제 명령 등록, GHCR 읽기(공개 패키지면 불필요; 비공개면 `read:packages` 토큰으로 `docker login`).
- [ ] **Step 5:** 환경별 `.env` 작성(사용자에게 메일 설정 값·도메인 받기): `APP_ENV`, `APP_KEY`(각각 생성), `APP_URL`, `DB_*`(환경별 다른 비밀번호), `OPS_NOTIFIER=discord`, `OPS_DISCORD_WEBHOOK`(ops 웹훅), `APP_ENV_LABEL`, `TRUSTED_PROXIES=172.30.0.0/16`, `GITHUB_REPO`, `GITHUB_INCIDENT_TOKEN`, `MAIL_*`. 권한 `600`, 소유자 `acp-deploy`.
- [ ] **Step 6:** 사용자가 DNS에 하위 도메인 2개를 VPS IP로 등록. 
- [ ] **Step 7 (사용자 확인 후):** Caddyfile 백업(`.bak-20261126`) → `deploy/caddy-snippet.caddy` 블록 2개 추가(`staging.<도메인> { reverse_proxy acp-staging-web:8080 }`, `app.<도메인> { reverse_proxy acp-prod-web:8080 }`) → `docker exec webstack-caddy-1 caddy validate --config /etc/caddy/Caddyfile` 통과 후 `caddy reload`. 기존 사이트 도메인 각각 `curl -sI` 200/30x 확인(전후 비교).
- [ ] **Step 8:** 깃헙 Environments 비밀값 등록(`DEPLOY_SSH_KEY`는 배포 전용 새 키, `DEPLOY_KNOWN_HOSTS`는 `ssh-keyscan` 결과).
- [ ] **Step 9 (사용자에게 선택 안내):** 스왑 2GB 추가 여부.
- [ ] **Step 10:** 모든 작업 완료 후 `claude-setup` 계정 삭제는 Task 17 끝에서.

---

## 마일스톤 D — 종단 확인과 포트폴리오

### Task 17: 종단 시나리오 실행, README, 시연 자료, 정리

**Files:**
- Create: `README.md`(한국어), `docs/architecture.md`(구조도 mermaid), `docs/demo/`(GIF·스크린샷)

- [ ] **Step 1: 시나리오 1** — 사용자가 이슈 양식으로 "게시글 목록에 페이지 번호 추가" 요청(`@claude`) → AI PR(봇 작성자) → `php-checks`·`secret-scan` 통과 → `risk:low` → 첫 AI 리뷰 댓글 → 사용자가 줄 단위 `@claude` 수정 요청 → 재작업 → 승인·머지 → 스테이징 배포 알림 → 운영 승인 → 운영 배포 알림. 각 단계 링크 수집.
- [ ] **Step 2: 시나리오 1-b** — `app/Security/` 를 건드리는 요청 → `risk:high`, "사람 확인 필요" 알림, PR 맨 위 고위험 요약, CODEOWNERS 승인 필수 확인.
- [ ] **Step 3: 시나리오 2(스테이징)** — 테스트 계정으로 로그인 5회 실패 → 잠금 → ops 알림 → 이메일 코드로 해제.
- [ ] **Step 4: 시나리오 3(스테이징)** — 글 6개 연속 → 글쓰기 제한·숨김·알림 → 이메일 코드로 해제. `/.env` 접근 → IP 차단 알림 → 서명 링크로 해제.
- [ ] **Step 5: 시나리오 4(스테이징)** — 스테이징 전용 에러 유발 라우트(`APP_ENV=staging`일 때만 등록, 관리자만 접근)로 예외 12회 → 에러 급증 알림 → incident 이슈(가림 처리 확인) → AI 분석 댓글·수정 PR. (이 라우트를 만드는 변경은 시나리오 1 방식으로 AI에게 요청해도 된다.)
- [ ] **Step 6:** 외부 가동 확인: 스테이징 web 컨테이너를 잠시 중지(`docker compose -p acp-staging stop web`)해 uptime 알림 확인 후 재기동. 토큰 만료 알림은 `workflow_dispatch` + 임시 변수로 확인 후 원복.
- [ ] **Step 7:** README: 한 장 구조도, "AI가 운영에 닿을 수 없는 이유", 위험도 게이트, 자동 차단 안전장치, 각 시나리오 실제 링크·GIF, 언어 교체 방법(`checks-php.yml`만 교체), 토큰 만료 운영 메모. 비밀값·서버 IP 노출 여부 `gitleaks detect` 재확인.
- [ ] **Step 8:** 서버 임시 계정 `claude-setup` 삭제, 이 PC의 `~/.ssh/claude_vps` 키 삭제 여부 사용자 확인.
- [ ] **Step 9:** README PR을 통상 흐름으로 머지.
