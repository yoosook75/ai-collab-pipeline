"""Tests for env_watch.py — run: python -m unittest deploy/ops/test_env_watch.py"""
import gzip
import json
import os
import sys
import tempfile
import unittest
from datetime import date

sys.path.insert(0, os.path.dirname(__file__))
import env_watch as ew  # noqa: E402

# 2026-11-25 10:00 KST == 2026-11-25 01:00 UTC
TS_DAY = 1795568400.0
TS_PREV_DAY = TS_DAY - 86400
DAY = date(2026, 11, 25)


def entry(uri, status, ts=TS_DAY, host="deskora.twstore.kr", size=0, ip="1.2.3.4", headers=None,
          ctype="text/plain"):
    return {
        "ts": ts, "status": status, "size": size,
        "request": {"remote_ip": ip, "client_ip": ip, "method": "GET", "host": host,
                    "uri": uri, "headers": headers or {"User-Agent": ["x"]}},
        "resp_headers": {"Set-Cookie": ["session=abc"], "Content-Type": [ctype]},
    }


class HtmlFallback(unittest.TestCase):
    def test_2xx_html_is_spa_fallback_not_suspicious(self):
        s = ew.summarize([entry("/.env", 200, ctype="text/html; charset=utf-8")], DAY)
        self.assertEqual(s["suspicious"], [])
        self.assertEqual(s["html_fallback"], 1)
        msg = ew.daily_message(s, DAY)
        self.assertEqual(msg["level"], "info")
        self.assertIn("HTML", msg["description"])

    def test_watch_ignores_html_fallback(self):
        with tempfile.TemporaryDirectory() as d:
            alerts = ew.watch([entry("/.env", 200, ts=100, ctype="text/html")], os.path.join(d, "s.json"), now=50)
            self.assertEqual(alerts, [])


class IsEnvPath(unittest.TestCase):
    def test_matches_env_variants(self):
        for uri in ["/.env", "/api/.env", "/.env.bak", "/.env.production?x=1", "/a/b/.env.local"]:
            self.assertTrue(ew.is_env_path(uri), uri)

    def test_ignores_non_env(self):
        for uri in ["/", "/environment", "/foo.env.js", "/api/products", "/envoy/.envrc2"]:
            self.assertFalse(ew.is_env_path(uri), uri)


class Summarize(unittest.TestCase):
    def test_counts_total_status_and_site_for_day_only(self):
        entries = [
            entry("/.env", 403), entry("/.env", 403), entry("/api/.env", 404),
            entry("/.env", 200, host="g7.twchatbot.twstore.kr", size=512),
            entry("/", 200),                       # not .env
            entry("/.env", 403, ts=TS_PREV_DAY),   # other day
        ]
        s = ew.summarize(entries, DAY)
        self.assertEqual(s["total"], 4)
        self.assertEqual(s["by_status"], {"403": 2, "404": 1, "200": 1})
        self.assertEqual(s["by_site"]["deskora.twstore.kr"], 3)
        self.assertEqual(len(s["suspicious"]), 1)
        self.assertEqual(s["suspicious"][0]["host"], "g7.twchatbot.twstore.kr")

    def test_message_marks_suspicious(self):
        s = ew.summarize([entry("/.env", 200, size=10)], DAY)
        msg = ew.daily_message(s, DAY)
        self.assertIn("2026-11-25", msg["title"])
        self.assertIn("의심", msg["description"])
        self.assertEqual(msg["level"], "critical")

    def test_message_quiet_day_is_info(self):
        msg = ew.daily_message(ew.summarize([entry("/.env", 403)], DAY), DAY)
        self.assertEqual(msg["level"], "info")
        self.assertIn("403", msg["description"])


class Mask(unittest.TestCase):
    def test_masks_query_values_headers_and_ip(self):
        e = entry("/.env?token=SECRET123&a=b", 200, ip="203.0.113.77",
                  headers={"Authorization": ["Bearer SECRET"], "User-Agent": ["curl"]})
        m = ew.mask(e)
        dumped = json.dumps(m)
        self.assertNotIn("SECRET", dumped)
        self.assertNotIn("session=abc", dumped)
        self.assertIn("token=***", m["uri"])
        self.assertEqual(m["ip"], "203.0.113.x")
        self.assertEqual(m["user_agent"], "curl")


class Watch(unittest.TestCase):
    def test_alerts_only_new_2xx_and_advances_state(self):
        with tempfile.TemporaryDirectory() as d:
            state = os.path.join(d, "state.json")
            entries = [entry("/.env", 403, ts=100), entry("/.env", 200, ts=200, size=5)]
            alerts = ew.watch(entries, state, now=50)  # first run looks back 10 min
            self.assertEqual(len(alerts), 1)
            self.assertEqual(ew.watch(entries, state), [])  # already seen
            alerts = ew.watch(entries + [entry("/x/.env", 206, ts=300)], state)
            self.assertEqual(len(alerts), 1)

    def test_first_run_does_not_alert_on_old_history(self):
        with tempfile.TemporaryDirectory() as d:
            state = os.path.join(d, "state.json")
            self.assertEqual(ew.watch([entry("/.env", 200, ts=1)], state, now=10_000), [])


class ReadLogs(unittest.TestCase):
    def test_reads_plain_and_gz_and_skips_bad_lines(self):
        with tempfile.TemporaryDirectory() as d:
            with open(os.path.join(d, "a.log"), "w") as f:
                f.write(json.dumps(entry("/.env", 403)) + "\nnot json\n")
            with gzip.open(os.path.join(d, "a-old.log.gz"), "wt") as f:
                f.write(json.dumps(entry("/.env", 404)) + "\n")
            got = list(ew.read_entries(d))
            self.assertEqual(sorted(e["status"] for e in got), [403, 404])


if __name__ == "__main__":
    unittest.main()
