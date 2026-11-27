"""Tests for php_secpatch.py — run: python -m unittest deploy/ops/test_php_secpatch.py"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import php_secpatch as sp  # noqa: E402

RELEASES = {
    "8.4.30": {"tags": []},
    "8.4.29": {"tags": ["security"]},
    "8.4.28": {"tags": ["security"]},
    "8.4.25": {"tags": []},
}
G7 = {"name": "g7-chatbot", "mode": "build"}
ACP = {"name": "acp-prod", "mode": "notify"}


class SecurityReleases(unittest.TestCase):
    def test_only_newer_security_releases_sorted(self):
        self.assertEqual(sp.security_releases_after(RELEASES, "8.4.25"), ["8.4.28", "8.4.29"])

    def test_none_when_current_is_patched(self):
        self.assertEqual(sp.security_releases_after(RELEASES, "8.4.29"), [])

    def test_ignores_other_minor_lines(self):
        rel = {"8.5.3": {"tags": ["security"]}, "8.4.29": {"tags": ["security"]}}
        self.assertEqual(sp.security_releases_after(rel, "8.4.28"), ["8.4.29"])


class Decide(unittest.TestCase):
    def test_no_security_release_means_nothing(self):
        self.assertEqual(sp.decide(G7, "8.4.29", "8.4.30", [])[0], "none")

    def test_apply_when_image_has_the_fix_in_same_minor(self):
        self.assertEqual(sp.decide(G7, "8.4.28", "8.4.29", ["8.4.29"])[0], "apply")

    def test_wait_when_image_not_rebuilt_yet(self):
        self.assertEqual(sp.decide(G7, "8.4.27", "8.4.28", ["8.4.28", "8.4.29"])[0], "wait")

    def test_never_auto_applies_a_minor_upgrade(self):
        action, reason = sp.decide(G7, "8.3.36", "8.4.29", ["8.3.37"])
        self.assertEqual(action, "notify")
        self.assertIn("버전업", reason)

    def test_never_auto_applies_an_app_version_upgrade(self):
        action, _ = sp.decide({"name": "pma", "mode": "pull"}, "8.3.36", "8.3.37", ["8.3.37"],
                              current_app="5.2.3", candidate_app="5.3.0")
        self.assertEqual(action, "notify")

    def test_app_patch_bump_is_fine(self):
        action, _ = sp.decide({"name": "pma", "mode": "pull"}, "8.3.36", "8.3.37", ["8.3.37"],
                              current_app="5.2.3", candidate_app="5.2.4")
        self.assertEqual(action, "apply")

    def test_notify_only_targets_never_apply(self):
        self.assertEqual(sp.decide(ACP, "8.4.28", None, ["8.4.29"])[0], "notify")


class FakeRunner:
    def __init__(self, php_after="8.4.29", health_ok=True):
        self.cmds, self.php_after, self.health_ok = [], php_after, health_ok

    def __call__(self, cmd):
        self.cmds.append(" ".join(cmd))
        joined = " ".join(cmd)
        if "PHP_VERSION" in joined:
            return 0, self.php_after
        if joined.startswith("curl"):
            return 0, "200" if self.health_ok else "502"
        if "{{.Image}}" in joined:
            return 0, "sha256:old"
        if "{{.Config.Image}}" in joined:
            return 0, "g7-chatbot-php"
        if "{{.State.Running}}" in joined:
            return 0, "true"
        return 0, ""


TARGET = {"name": "g7-chatbot", "mode": "build", "container": "g7-chatbot-php", "dir": "/opt/apps/g7-chatbot",
          "service": "php", "health": "https://g7.example/", "resolve": "g7.example:443:127.0.0.1"}


class Apply(unittest.TestCase):
    def test_success_backs_up_rebuilds_and_verifies(self):
        run = FakeRunner()
        ok, msg = sp.apply_target(TARGET, "8.4.29", run=run, settle=0)
        self.assertTrue(ok, msg)
        log = "\n".join(run.cmds)
        self.assertIn("docker tag sha256:old g7-chatbot-php:secpatch-backup", log)
        self.assertIn("build --pull php", log)
        self.assertIn("-f /opt/apps/g7-chatbot/compose.yaml", log)
        self.assertNotIn("secpatch-backup g7-chatbot-php", log)  # no rollback

    def test_failed_health_check_rolls_back(self):
        run = FakeRunner(health_ok=False)
        ok, msg = sp.apply_target(TARGET, "8.4.29", run=run, settle=0)
        self.assertFalse(ok)
        self.assertIn("되돌", msg)
        self.assertIn("docker tag g7-chatbot-php:secpatch-backup g7-chatbot-php", "\n".join(run.cmds))

    def test_wrong_php_version_after_update_rolls_back(self):
        run = FakeRunner(php_after="8.4.28")
        ok, _ = sp.apply_target(TARGET, "8.4.29", run=run, settle=0)
        self.assertFalse(ok)


NGINX_ADVISORIES = """
<li><p>Excessive CPU usage<br>Not vulnerable: 1.31.10+, 1.30.8+<br>Vulnerable: 1.21.4-1.31.9</p></li>
<li><p>Use-after-free<br>Not vulnerable: 1.31.9+, 1.30.7+<br>Vulnerable: 0.6.18-1.31.8</p></li>
<li><p>NULL pointer<br>Not vulnerable: 1.31.9+<br>Vulnerable: 1.31.4-1.31.8</p></li>
<li><p>Old<br>Not vulnerable: 1.29.7+, 1.28.3+<br></p></li>
"""

NGINX_WEB = {"name": "deskora-web", "software": "nginx", "mode": "build", "base": "nginx:1.30-alpine",
             "container": "deskora-web", "dir": "/opt/apps/deskora-shopping-mall/api", "service": "web",
             "compose_files": ["compose.yaml", "compose.override.yaml"],
             "health": "https://deskora.example/", "resolve": "deskora.example:443:127.0.0.1"}


class Nginx(unittest.TestCase):
    def test_parses_fixed_versions_from_advisories(self):
        rel = sp.nginx_fixed_releases(NGINX_ADVISORIES)
        self.assertEqual(sp.security_releases_after(rel, "1.30.6"), ["1.30.7", "1.30.8"])
        self.assertEqual(sp.security_releases_after(rel, "1.30.8"), [])

    def test_same_rules_no_auto_minor_upgrade(self):
        rel = sp.nginx_fixed_releases(NGINX_ADVISORIES)
        sec = sp.security_releases_after(rel, "1.30.7")
        self.assertEqual(sp.decide(NGINX_WEB, "1.30.7", "1.30.8", sec)[0], "apply")
        self.assertEqual(sp.decide(NGINX_WEB, "1.30.7", "1.31.10", sec)[0], "notify")

    def test_reads_running_and_image_nginx_version(self):
        def run(cmd):
            j = " ".join(cmd)
            if "nginx -v" in j:
                return 0, "nginx version: nginx/1.30.7"
            if "image inspect" in j:
                return 0, "PATH=/usr/bin\nNGINX_VERSION=1.30.8\n"
            return 0, ""
        self.assertEqual(sp.container_version(NGINX_WEB, run), "1.30.7")
        self.assertEqual(sp.image_version(NGINX_WEB, "nginx:1.30-alpine", run), ("1.30.8", None))

    def test_apply_keeps_compose_override_and_verifies_nginx_version(self):
        class R(FakeRunner):
            def __call__(self, cmd):
                j = " ".join(cmd)
                if "nginx -v" in j:
                    self.cmds.append(j)
                    return 0, "nginx version: nginx/1.30.8"
                return super().__call__(cmd)
        run = R()
        ok, msg = sp.apply_target(NGINX_WEB, "1.30.8", run=run, settle=0)
        self.assertTrue(ok, msg)
        log = "\n".join(run.cmds)
        self.assertIn("-f /opt/apps/deskora-shopping-mall/api/compose.yaml -f /opt/apps/deskora-shopping-mall/api/compose.override.yaml", log)
        self.assertIn("build --pull web", log)

    def test_check_schedules_nginx_patch(self):
        def run(cmd):
            j = " ".join(cmd)
            if "nginx -v" in j:
                return 0, "nginx version: nginx/1.30.7"
            if "image inspect" in j:
                return 0, "NGINX_VERSION=1.30.8\n"
            return 0, ""
        orig = sp.fetch_releases
        sp.fetch_releases = lambda software, line: sp.nginx_fixed_releases(NGINX_ADVISORIES)
        try:
            st = {}
            pending = sp.check([NGINX_WEB], st, dry_run=True, run=run)
        finally:
            sp.fetch_releases = orig
        self.assertEqual(pending, [{"name": "deskora-web", "expected": "1.30.8"}])


class NginxEol(unittest.TestCase):
    def test_notifies_when_running_branch_no_longer_gets_fixes(self):
        def run(cmd):
            return (0, "nginx version: nginx/1.27.5") if "nginx -v" in " ".join(cmd) else (0, "")
        orig = sp.fetch_releases
        sp.fetch_releases = lambda software, line: sp.nginx_fixed_releases(NGINX_ADVISORIES)
        sent = []
        orig_send = sp.send
        sp.send = lambda title, desc, level, dry_run=False: sent.append(desc)
        try:
            pending = sp.check([NGINX_WEB], {}, run=run)
        finally:
            sp.fetch_releases, sp.send = orig, orig_send
        self.assertEqual(pending, [])
        self.assertTrue(sent and "지원" in sent[0] and "1.27" in sent[0], sent)


class State(unittest.TestCase):
    def test_notifies_once_per_target_version(self):
        with tempfile.TemporaryDirectory() as d:
            p = os.path.join(d, "state.json")
            st = sp.load_state(p)
            self.assertTrue(sp.should_notify(st, "g7-chatbot", "wait:8.4.29"))
            sp.save_state(p, st)
            st = sp.load_state(p)
            self.assertFalse(sp.should_notify(st, "g7-chatbot", "wait:8.4.29"))
            self.assertTrue(sp.should_notify(st, "g7-chatbot", "apply:8.4.29"))


if __name__ == "__main__":
    unittest.main()
