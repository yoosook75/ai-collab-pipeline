#!/usr/bin/env python3
"""`.env` probe watcher for the shared Caddy access logs. No AI calls, stdlib only.

  env_watch.py daily [--date YYYY-MM-DD] [--dry-run]   previous KST day summary -> Discord (once)
  env_watch.py watch [--dry-run]                       every 5 min: 2xx on a .env path -> immediate alert
  env_watch.py extract --date YYYY-MM-DD               masked related records (JSON lines) for analysis
"""
import argparse
import gzip
import json
import os
import re
import sys
import time
import urllib.request
from collections import Counter
from datetime import date, datetime, timedelta, timezone
from urllib.parse import parse_qsl, urlsplit

KST = timezone(timedelta(hours=9))
LOG_DIR = os.environ.get("ENVWATCH_LOG_DIR", "/var/lib/docker/volumes/webstack_caddy_data/_data/access")
ENV_FILE = os.environ.get("ENVWATCH_ENV_FILE", "/opt/acp/prod/.env")
STATE_FILE = os.environ.get("ENVWATCH_STATE", "/var/lib/acp-envwatch/state.json")
FIRST_RUN_LOOKBACK = 600
_ENV_SEGMENT = re.compile(r"^\.env(\..+)?$")


def is_env_path(uri):
    path = urlsplit(uri).path
    return any(_ENV_SEGMENT.match(seg) for seg in path.split("/"))


def _is_2xx(e):
    return 200 <= int(e.get("status", 0)) < 300


def _is_html(e):
    ctype = (e.get("resp_headers", {}).get("Content-Type") or [""])[0]
    return ctype.lower().startswith("text/html")


def is_suspicious(e):
    """2xx with a non-HTML body. 2xx HTML is an SPA serving its index page, not the file."""
    return _is_2xx(e) and not _is_html(e)


def kst_day(ts):
    return datetime.fromtimestamp(ts, KST).date()


def read_entries(log_dir, since_ts=None, include_rolled=True):
    for name in sorted(os.listdir(log_dir)):
        p = os.path.join(log_dir, name)
        if not (name.endswith(".log") or (include_rolled and name.endswith(".log.gz"))):
            continue
        if since_ts is not None and os.path.getmtime(p) < since_ts:
            continue
        opener = gzip.open if name.endswith(".gz") else open
        with opener(p, "rt", encoding="utf-8", errors="replace") as f:
            for line in f:
                try:
                    e = json.loads(line)
                except ValueError:
                    continue
                if isinstance(e, dict) and "request" in e:
                    yield e


def _mask_ip(ip):
    if ":" in ip:
        return ":".join(ip.split(":")[:3]) + ":x"
    parts = ip.split(".")
    return ".".join(parts[:3] + ["x"]) if len(parts) == 4 else "x"


def mask(e):
    r = e.get("request", {})
    u = urlsplit(r.get("uri", ""))
    q = "&".join(f"{k}=***" for k, _ in parse_qsl(u.query, keep_blank_values=True))
    ua = (r.get("headers", {}).get("User-Agent") or [""])[0][:120]
    return {
        "time": datetime.fromtimestamp(e.get("ts", 0), KST).isoformat(timespec="seconds"),
        "host": r.get("host", ""), "method": r.get("method", ""),
        "uri": u.path + (f"?{q}" if q else ""),
        "status": e.get("status"), "size": e.get("size"),
        "ip": _mask_ip(r.get("client_ip") or r.get("remote_ip") or ""),
        "user_agent": ua,
    }


def summarize(entries, day):
    hits = [e for e in entries if kst_day(e.get("ts", 0)) == day and is_env_path(e["request"].get("uri", ""))]
    return {
        "total": len(hits),
        "by_status": dict(Counter(str(e.get("status")) for e in hits)),
        "by_site": dict(Counter(e["request"].get("host", "?") for e in hits)),
        "suspicious": [mask(e) for e in hits if is_suspicious(e)],
        "html_fallback": sum(1 for e in hits if _is_2xx(e) and _is_html(e)),
    }


def daily_message(s, day):
    status = " · ".join(f"{k} ×{v}" for k, v in sorted(s["by_status"].items())) or "없음"
    sites = "\n".join(f"- {k}: {v}건" for k, v in sorted(s["by_site"].items(), key=lambda x: -x[1])) or "- 없음"
    lines = [f"전체 요청: **{s['total']}건**", f"응답 코드: {status}", "사이트별:", sites, ""]
    if s["suspicious"]:
        lines.append(f"⚠️ **의심 응답(2xx) {len(s['suspicious'])}건**: 실제 파일 노출 가능성, 확인 필요")
        lines += [f"`{m['time']} {m['host']}{m['uri']} → {m['status']} ({m['size']}B)`" for m in s["suspicious"][:10]]
    else:
        lines.append("✅ 의심 응답(2xx, HTML 아님) 없음")
    if s.get("html_fallback"):
        lines.append(f"ℹ️ 200이지만 HTML(홈페이지 대체 응답) {s['html_fallback']}건: 파일 노출 아님")
    return {"title": f".env 접근 일일 요약 ({day.isoformat()}, KST)",
            "description": "\n".join(lines), "level": "critical" if s["suspicious"] else "info"}


def watch(entries, state_path, now=None):
    now = time.time() if now is None else now
    try:
        with open(state_path) as f:
            last = float(json.load(f)["last_ts"])
    except (OSError, ValueError, KeyError):
        last = now - FIRST_RUN_LOOKBACK
    new = [e for e in entries if float(e.get("ts", 0)) > last]
    hits = [e for e in new if is_env_path(e["request"].get("uri", "")) and is_suspicious(e)]
    newest = max([last] + [float(e.get("ts", 0)) for e in new])
    os.makedirs(os.path.dirname(state_path) or ".", exist_ok=True)
    with open(state_path, "w") as f:
        json.dump({"last_ts": newest}, f)
    return [mask(e) for e in hits]


def _webhook():
    with open(ENV_FILE) as f:
        for line in f:
            if line.startswith("OPS_DISCORD_WEBHOOK="):
                return line.split("=", 1)[1].strip().strip('"')
    raise SystemExit("OPS_DISCORD_WEBHOOK not found")


def send(msg, dry_run=False):
    color = {"critical": 0xE03E3E, "info": 0x3B82F6}[msg["level"]]
    payload = {"embeds": [{"title": msg["title"][:256], "description": msg["description"][:4096], "color": color}]}
    if dry_run:
        print(json.dumps(payload, ensure_ascii=False, indent=1))
        return
    req = urllib.request.Request(_webhook(), data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json", "User-Agent": "acp-env-watch/1.0"})
    urllib.request.urlopen(req, timeout=15).read()


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["daily", "watch", "extract"])
    ap.add_argument("--date")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args(argv)
    if a.mode == "watch":
        alerts = watch(read_entries(LOG_DIR, include_rolled=False), STATE_FILE)
        if alerts:
            body = "\n".join(f"`{m['time']} {m['host']}{m['uri']} → {m['status']} ({m['size']}B) ip={m['ip']}`" for m in alerts[:10])
            send({"title": f"🚨 .env 파일 노출 의심: 2xx 응답 {len(alerts)}건", "level": "critical",
                  "description": body + "\n\n즉시 확인하세요. 상세(가림 처리): `env_watch.py extract --date 오늘`"}, a.dry_run)
        return 0
    day = date.fromisoformat(a.date) if a.date else (datetime.now(KST).date() - timedelta(days=1))
    since = datetime.combine(day, datetime.min.time(), KST).timestamp()
    entries = read_entries(LOG_DIR, since_ts=since)
    if a.mode == "extract":
        for e in entries:
            if kst_day(e.get("ts", 0)) == day and is_env_path(e["request"].get("uri", "")):
                print(json.dumps(mask(e), ensure_ascii=False))
        return 0
    send(daily_message(summarize(entries, day), day), a.dry_run)
    return 0


if __name__ == "__main__":
    sys.exit(main())
