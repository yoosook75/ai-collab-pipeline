#!/usr/bin/env python3
"""Security-patch watcher for the shared VPS containers (PHP and nginx). No AI calls, stdlib only.

Only acts when upstream marks a newer release in the *same minor line* as a security fix:
  - PHP: php.net releases tagged "security"
  - nginx: "Not vulnerable: x.y.z+" versions on nginx.org/en/security_advisories.html
Never upgrades a minor/major version (PHP 8.4 -> 8.5, nginx 1.30 -> 1.31) or an app
(e.g. phpMyAdmin) minor version.

  php_secpatch.py check [--dry-run]   daily: compare running PHP vs php.net security releases,
                                      schedule tonight's apply or notify (Discord, once per version)
  php_secpatch.py apply [--dry-run]   nightly: apply scheduled patches, verify, roll back on failure
"""
import argparse
import json
import os
import re
import subprocess
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from env_watch import _webhook  # noqa: E402  (same OPS_DISCORD_WEBHOOK source)

CONFIG = os.environ.get("SECPATCH_CONFIG", "/etc/acp-secpatch/targets.json")
STATE_FILE = os.environ.get("SECPATCH_STATE", "/var/lib/acp-secpatch/state.json")
UA = "acp-php-secpatch/1.0"
BACKUP_TAG = "secpatch-backup"


# ---------- pure logic ----------

def ver(s):
    return tuple(int(x) for x in s.split("."))


def minor(s):
    return ver(s)[:2]


def security_releases_after(releases, current):
    """Versions newer than `current`, same minor line, tagged security by php.net, ascending."""
    out = [v for v, info in releases.items()
           if minor(v) == minor(current) and ver(v) > ver(current) and "security" in (info.get("tags") or [])]
    return sorted(out, key=ver)


LABELS = {"php": "PHP", "nginx": "nginx"}


def software(target):
    return target.get("software", "php")


def nginx_fixed_releases(html):
    """nginx.org advisories page -> {version: {"tags": ["security"]}} for every 'Not vulnerable: x.y.z+'."""
    out = {}
    for block in re.findall(r"Not vulnerable:\s*([^<\n]*)", html):
        for v in re.findall(r"(\d+\.\d+\.\d+)\+", block):
            out[v] = {"tags": ["security"]}
    return out


def is_unsupported_line(target, current, releases):
    """nginx는 최신(mainline)·안정(stable) 두 계열만 보안 패치를 냅니다. 지금 계열이 그 둘이 아니면
    앞으로도 패치가 없으므로 알림 문구를, 아니면 None을 돌려줍니다 (자동 버전업은 하지 않음)."""
    if software(target) != "nginx" or not releases:
        return None
    supported = sorted({minor(v) for v in releases}, reverse=True)[:2]
    if minor(current) in supported:
        return None
    stable = "%d.%d" % supported[-1]
    return (f"nginx {'%d.%d' % minor(current)} 계열은 지원이 끝나 보안 패치가 더 나오지 않아요. "
            f"안정판 {stable} 계열로 버전업이 필요해요 (자동 적용 안 함)")


def decide(target, current, candidate, security, current_app=None, candidate_app=None):
    """-> (action, reason). action: none | apply | wait | notify"""
    if not security:
        return "none", "보안 패치 없음"
    need = security[-1]
    if target.get("mode") == "notify":
        return "notify", f"보안 패치 {need} 출시. 자동 적용 대상이 아니므로 재배포가 필요해요"
    if candidate is None:
        return "wait", f"보안 패치 {need} 출시, 공식 이미지 버전 확인 실패"
    if minor(candidate) != minor(current):
        return "notify", f"공식 이미지가 {LABELS[software(target)]} {candidate}로 바뀌어 버전업이 필요해 자동 적용하지 않아요"
    if current_app and candidate_app and minor(candidate_app) != minor(current_app):
        return "notify", f"앱 버전이 {current_app} → {candidate_app}로 바뀌는 버전업이라 자동 적용하지 않아요"
    if ver(candidate) < ver(need):
        return "wait", f"보안 패치 {need} 출시, 공식 이미지는 아직 {candidate}"
    return "apply", f"{LABELS[software(target)]} {current} → {candidate} (보안 패치 {', '.join(security)})"


def load_state(path):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def save_state(path, st):
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w") as f:
        json.dump(st, f, ensure_ascii=False, indent=1)


def should_notify(st, name, key):
    """True the first time `key` is seen for `name`; records it."""
    seen = st.setdefault("notified", {})
    if seen.get(name) == key:
        return False
    seen[name] = key
    return True


# ---------- side effects ----------

def run_cmd(cmd):
    p = subprocess.run(cmd, capture_output=True, text=True)
    return p.returncode, (p.stdout or p.stderr).strip()


VERSION_PROBE = {
    "php": ["php", "-r", "echo PHP_VERSION;"],
    "nginx": ["nginx", "-v"],  # prints "nginx version: nginx/1.30.8" on stderr
}
IMAGE_VERSION_ENV = {"php": "PHP_VERSION", "nginx": "NGINX_VERSION"}


def container_version(target, run):
    rc, out = run(["docker", "exec", target["container"]] + VERSION_PROBE[software(target)])
    m = re.search(r"(\d+\.\d+\.\d+)", out or "") if rc == 0 else None
    return m.group(1) if m else None


def image_version(target, image, run):
    """-> (software version, app VERSION or None) from the image's env."""
    rc, out = run(["docker", "image", "inspect", image, "--format", "{{range .Config.Env}}{{println .}}{{end}}"])
    env = dict(line.split("=", 1) for line in out.splitlines() if "=" in line) if rc == 0 else {}
    return env.get(IMAGE_VERSION_ENV[software(target)]), env.get("VERSION") if software(target) == "php" else None


def _get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    return urllib.request.urlopen(req, timeout=20).read()


def fetch_releases(sw, minor_line):
    if sw == "nginx":
        return nginx_fixed_releases(_get("https://nginx.org/en/security_advisories.html").decode("utf-8", "replace"))
    return json.loads(_get(f"https://www.php.net/releases/index.php?json&version={minor_line}&max=10"))


def healthy(target, run):
    cmd = ["curl", "-s", "-o", "/dev/null", "-m", "15", "-w", "%{http_code}"]
    if target.get("resolve"):
        cmd += ["--resolve", target["resolve"]]
    rc, code = run(cmd + [target["health"]])
    return rc == 0 and code[:1] in ("2", "3")


def apply_target(t, expected, run=run_cmd, settle=15):
    """Backup current image, update, verify (running + PHP version + health). Roll back on failure."""
    c, d, svc = t["container"], t["dir"], t["service"]
    files = t.get("compose_files") or [t.get("compose_file", "compose.yaml")]
    compose = ["docker", "compose", "--project-directory", d]
    for f in files:  # override 파일도 명시해야 재배포 때 마운트 설정이 빠지지 않음
        compose += ["-f", f"{d}/{f}"]
    _, img_id = run(["docker", "inspect", c, "--format", "{{.Image}}"])
    _, img_name = run(["docker", "inspect", c, "--format", "{{.Config.Image}}"])
    repo = img_name.rsplit(":", 1)[0] if ":" in img_name.split("/")[-1] else img_name
    backup = f"{repo}:{BACKUP_TAG}"
    run(["docker", "tag", img_id, backup])

    step = compose + (["build", "--pull", svc] if t["mode"] == "build" else ["pull", svc])
    rc, out = run(step)
    if rc == 0:
        rc, out = run(compose + ["up", "-d", "--no-deps", "--no-build", svc])
    time.sleep(settle)

    def verify():
        _, running = run(["docker", "inspect", c, "--format", "{{.State.Running}}"])
        now = container_version(t, run)
        return running == "true" and now == expected and healthy(t, run), now

    good, now = verify() if rc == 0 else (False, None)
    if good:
        return True, f"✅ {t['name']}: {LABELS[software(t)]} {now} 적용, 사이트 정상"

    run(["docker", "tag", backup, img_name])
    run(compose + ["up", "-d", "--no-deps", "--no-build", svc])
    time.sleep(settle)
    back_ok = healthy(t, run)
    why = out[-300:] if rc != 0 else f"점검 실패 ({LABELS[software(t)]} {now}, 기대 {expected})"
    return False, f"🚨 {t['name']}: 적용 실패 → 이전 이미지로 되돌림 ({'정상' if back_ok else '되돌린 뒤에도 비정상, 확인 필요'})\n{why}"


def send(title, desc, level, dry_run=False):
    color = {"critical": 0xE03E3E, "warn": 0xF59E0B, "info": 0x3B82F6, "ok": 0x22C55E}[level]
    payload = {"embeds": [{"title": title[:256], "description": desc[:4000], "color": color}]}
    if dry_run:
        print(json.dumps(payload, ensure_ascii=False, indent=1))
        return
    req = urllib.request.Request(_webhook(), data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json", "User-Agent": UA})
    urllib.request.urlopen(req, timeout=15).read()


# ---------- modes ----------

def check(targets, st, dry_run=False, run=run_cmd):
    pending, lines, level = [], [], "info"
    cache = {}
    for t in targets:
        cur = container_version(t, run)
        if not cur:
            continue
        key = (software(t), "%d.%d" % minor(cur))
        if key not in cache:
            cache[key] = fetch_releases(*key)
        sec = security_releases_after(cache[key], cur)
        cand = cand_app = cur_app = None
        if sec and t.get("mode") != "notify":
            run(["docker", "pull", "-q", t["base"]])
            cand, cand_app = image_version(t, t["base"], run)
            if t.get("app_from_image"):
                img = run(["docker", "inspect", t["container"], "--format", "{{.Image}}"])[1]
                _, cur_app = image_version(t, img, run)
        action, reason = decide(t, cur, cand, sec, cur_app, cand_app)
        eol = action == "none" and is_unsupported_line(t, cur, cache[key])
        if eol:
            action, reason = "notify", eol
        if action == "none":
            continue
        if action == "apply":
            pending.append({"name": t["name"], "expected": cand})
        if should_notify(st, t["name"], f"{action}:{cand or (sec[-1] if sec else 'eol-' + key[1])}"):
            lines.append(f"- **{t['name']}**: {reason}")
            if action in ("apply", "notify"):
                level = "warn"
    st["pending"] = pending
    if lines:
        head = "오늘 밤(다음 날 새벽 04:00 KST)에 자동 적용 예정이에요." if pending else "자동 적용 대상은 없어요."
        send("보안 패치 확인 (PHP·nginx)", head + "\n" + "\n".join(lines), level, dry_run)
    return pending


def apply(targets, st, dry_run=False, run=run_cmd):
    pending = st.get("pending") or []
    if not pending:
        return []
    by_name = {t["name"]: t for t in targets}
    results = []
    for p in pending:
        t = by_name.get(p["name"])
        if not t:
            continue
        if dry_run:
            results.append((True, f"(dry-run) {t['name']} → {p['expected']}"))
            continue
        results.append(apply_target(t, p["expected"], run=run))
    st["pending"] = []
    ok = all(r[0] for r in results)
    send("보안 패치 적용 " + ("완료" if ok else "이상 발견"), "\n".join(r[1] for r in results),
         "ok" if ok else "critical", dry_run)
    return results


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["check", "apply"])
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args(argv)
    with open(CONFIG) as f:
        targets = json.load(f)
    st = load_state(STATE_FILE)
    (check if a.mode == "check" else apply)(targets, st, a.dry_run)
    if not a.dry_run:
        save_state(STATE_FILE, st)
    return 0


if __name__ == "__main__":
    sys.exit(main())
