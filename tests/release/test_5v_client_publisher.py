"""Offline tests for EXE/APK patching and verified FTP publishing on 5v.pl."""
from __future__ import annotations

import hashlib
import io
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools/release/5v-installer"))
from mucho5v import InstallError
import muchoclients5v as clients
import PUBLISH_CLIENTS_5V as launcher
import INSTALL_5V as installer


class FakeConnection:
    def __init__(self):
        self.files = {}
        self.cwd_value = "/public"
    def voidcmd(self, cmd):
        assert cmd == "TYPE I"
    def size(self, path):
        value = self.files.get(path)
        return None if value is None else value[0]
    def storbinary(self, command, file, blocksize=0):
        name = command.split(" ", 1)[1]
        data = file.read()
        self.files["mc/public/clients/" + name] = (len(data), data)
    def pwd(self):
        return self.cwd_value


class FakeFtp:
    def __init__(self):
        self.root = "/public"
        self.conn = FakeConnection()
        self.history = []
    def exists_dir(self, base, name):
        assert base == self.root
        return name == "mc"
    def ensure_dir(self, base, name):
        assert base == self.root
        self.history.append("mkdir:" + name)
    def cd(self, base):
        assert base == self.root
    def rename(self, base, before, after):
        assert base == self.root
        self.history.append("rename:" + before + "->" + after)
        self.conn.files[after] = self.conn.files.pop(before)
    def delete(self, base, name):
        assert base == self.root
        self.conn.files.pop(name, None)
    def put(self, base, name, payload):
        assert base == self.root
        self.conn.files[name] = (len(payload), payload)
    def get(self, base, name, optional=False):
        item = self.conn.files.get(name)
        return item[1] if item else None


class FakeSite:
    origin = "https://mine.5v.pl"


class ClientFunctions(unittest.TestCase):
    def test_checksum_and_fingerprint_matches_web_patch_protocol(self):
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "GeometryDash.apk"
            blob = b"A" * 100000 + b"B" * 81000
            p.write_bytes(blob)
            self.assertEqual(clients.sha256_file(p), hashlib.sha256(blob).hexdigest())
            expected = hashlib.sha256(blob[:65536] + blob[-65536:]).hexdigest()
            self.assertEqual(clients.fingerprint_file(p), expected)

    def test_source_is_given_by_user_and_checked(self):
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "GeometryDash.exe"
            p.write_bytes(b"MZ" + b"x" * 2048)
            self.assertEqual(clients.read_source(p, ".exe"), p)
            with self.assertRaises(InstallError):
                clients.read_source(p, ".apk")
            with self.assertRaises(InstallError):
                clients.read_source(Path(folder) / "absent.apk", ".apk")

    def test_csrf_is_from_exact_admin_form(self):
        csrf = "a" * 48
        form = ('<form id="sharedPatcher" data-endpoint="/mc/admin.php" '
                'data-csrf="' + csrf + '">').encode()
        self.assertEqual(clients.patcher_csrf(form), csrf)
        with self.assertRaises(InstallError):
            clients.patcher_csrf(b'<form data-csrf="' + csrf.encode() + b'">')

    def test_public_urls_are_site_scoped_and_content_versioned(self):
        self.assertEqual(
            clients._public_url("https://mine.5v.pl",
                "GeometryDash-MuchoCore-" + "a" * 12 + ".apk"),
            "https://mine.5v.pl/mc/public/clients/GeometryDash-MuchoCore-"
            + "a" * 12 + ".apk")
        with self.assertRaises(InstallError):
            clients._public_url("https://evil.example", "GeometryDash-MuchoCore-"
                                + "a" * 12 + ".apk")
        with self.assertRaises(InstallError):
            clients._public_url("https://mine.5v.pl", "../password.php")

    def test_windows_publication_is_atomic_and_checks_public_https(self):
        ftp = FakeFtp()
        source = b"MZ" + b"\x00" * 2048
        with tempfile.TemporaryDirectory() as folder:
            file = Path(folder) / "patched.exe"
            file.write_bytes(source)
            report = {"sha256": hashlib.sha256(source).hexdigest(),
                      "size": len(source)}
            with patch.object(clients, "_probe_public") as probe:
                url = clients.publish_windows(ftp, FakeSite(), file, report)
            self.assertTrue(url.endswith(report["sha256"][:12] + ".exe"))
            self.assertIn("mc/public/clients/GeometryDash-MuchoCore-"
                          + report["sha256"][:12] + ".exe", ftp.conn.files)
            probe.assert_called_once()
            with self.assertRaises(InstallError):
                clients.publish_windows(ftp, FakeSite(), file, report)

    def test_signed_apk_is_moved_not_copied_to_preserve_5v_disk(self):
        ftp = FakeFtp()
        digest = "c" * 64
        job_id = "d" * 32
        source = "mc/storage/shared-client-patcher/" + job_id + "/result.apk"
        ftp.conn.files[source] = (175000000, b"")
        with patch.object(clients, "_probe_public") as probe:
            url = clients.publish_android(
                ftp, FakeSite(),
                {"output_sha256": digest, "output_size": 175000000,
                 "job_id": job_id}, Path("/nonexistent"))
        self.assertTrue(url.endswith(digest[:12] + ".apk"))
        self.assertNotIn(source, ftp.conn.files)
        self.assertIn("mc/public/clients/GeometryDash-MuchoCore-"
                      + digest[:12] + ".apk", ftp.conn.files)
        probe.assert_called_once()

    def test_apk_rejects_invalid_job_metadata(self):
        with self.assertRaises(InstallError):
            clients.publish_android(FakeFtp(), FakeSite(),
                {"output_sha256": "not-a-sha", "output_size": 175000000,
                 "job_id": "d" * 32}, Path("/nowhere"))

    def test_apk_upload_uses_existing_php_patcher_api(self):
        php = (ROOT / "compat/php7/patcher.php").read_text(encoding="utf-8")
        job = (ROOT / "compat/php7/patcher/Job.php").read_text(encoding="utf-8")
        for action in ("shared-patcher-start", "shared-patcher-chunk",
                       "shared-patcher-step", "shared-patcher-status"):
            self.assertIn(action, php)
        for key in ("result.apk", "signed", "signature_schemes", "v1", "v2",
                    "php7-apk-signer", "output_sha256"):
            target = job + (ROOT / "compat/php7/patcher/Signer.php").read_text()
            self.assertIn(key, target)
        self.assertIn("fingerprint", job)

    def test_bundled_windows_patcher_is_canonical_and_release_has_no_game_binary(self):
        builder = (ROOT / "tools/release/build-php7-native.sh").read_text(encoding="utf-8")
        self.assertIn('cp "$ROOT/tools/client/client-patch.py" "$STAGE/gd_windows_patcher.py"', builder)
        for name in ("PUBLISH_CLIENTS_5V.py", "muchoclients5v.py",
                     "START_CLIENTS_WINDOWS.cmd", "START_CLIENTS_LINUX.sh"):
            self.assertIn(name, builder)
        self.assertNotIn('cp "$ROOT/GeometryDash.exe"', builder)
        self.assertNotIn('cp "$ROOT/GeometryDash.apk"', builder)

    def test_quick_install_does_not_prompt_for_missing_games(self):
        with patch.object(launcher, "find_original", return_value=None) as detect, \
             patch.object(launcher, "ask",
                          side_effect=AssertionError("Unexpected user prompt")):
            exe, apk, rights = launcher.ask_clients()
        self.assertEqual((exe, apk, rights), (None, None, False))
        self.assertEqual(detect.call_count, 2)
        self.assertTrue(all(call.kwargs.get("ask_for_missing") is False
                            for call in detect.call_args_list))

    def test_no_auto_publication_without_redistribution_rights(self):
        with patch.object(launcher, "find_original", side_effect=[Path("/owned.exe"), None]), \
             patch.object(launcher, "ask", return_value="НЕТ"):
            exe, apk, rights = launcher.ask_clients()
        self.assertFalse(rights)
        self.assertIsNone(apk)
        self.assertEqual(exe, Path("/owned.exe"))

    def test_android_job_refuses_unsigned_or_unverified_report(self):
        with tempfile.TemporaryDirectory() as folder:
            apk = Path(folder) / "GeometryDash.apk"
            apk.write_bytes(b"PK" + b"z" * 2048)
            csrf = "b" * 48
            class FakeAdmin:
                origin = "https://mine.5v.pl"
                def request(self, path, form=None):
                    return 200, ('<form id="sharedPatcher" data-csrf="' +
                                 csrf + '">').encode()
            status = [
                {"ok": True, "phase": "idle"},
                {"ok": True, "phase": "upload", "offset": 0, "id": "e" * 32},
                {"ok": True, "phase": "done", "id": "e" * 32, "report": {
                    "signed": False, "signature_schemes": ["v1"],
                    "output_sha256": "a" * 64, "output_size": 2700,
                    "server_url": FakeAdmin.origin, "replacement_count": 1,
                }},
            ]
            with patch.object(clients, "_urlencoded", side_effect=status), \
                 patch.object(clients, "_chunk_request", return_value={
                     "ok": True, "phase": "upload", "offset": apk.stat().st_size
                 }):
                with self.assertRaises(InstallError):
                    clients.patch_android(FakeAdmin(), apk, FakeAdmin.origin,
                                          progress=lambda _: None)

    def test_download_index_preserves_old_clients_and_custom_page(self):
        ftp = FakeFtp()
        site = FakeSite()
        old = site.origin + clients.PUBLIC_PREFIX + \
            "GeometryDash-MuchoCore-" + "a" * 12 + ".exe"
        new = site.origin + clients.PUBLIC_PREFIX + \
            "GeometryDash-MuchoCore-" + "b" * 12 + ".apk"
        clients.publish_index(ftp, site, {"exe": {"url": old, "sha256": "a" * 64}})
        clients.publish_index(ftp, site, {"apk": {"url": new, "sha256": "b" * 64}})
        published = ftp.conn.files["mc/public/clients/index.html"][1]
        self.assertIn(old.encode(), published)
        self.assertIn(new.encode(), published)
        self.assertIn(b"MUCHOCORE_CLIENTS_INDEX_V1", published)

        ftp.conn.files["mc/public/clients/index.html"] = (16, b"User own homepage")
        with self.assertRaises(InstallError):
            clients.publish_index(ftp, site, {"apk": {"url": new, "sha256": "b" * 64}})
        self.assertEqual(ftp.conn.files["mc/public/clients/index.html"][1],
                         b"User own homepage")

    def test_static_download_landing_does_not_disclose_secrets(self):
        ftp = FakeFtp()
        clients.publish_index(ftp, FakeSite(), {
            "exe": {"url": FakeSite.origin + clients.PUBLIC_PREFIX +
                    "GeometryDash-MuchoCore-" + "a" * 12 + ".exe",
                    "sha256": "a" * 64}
        })
        html = ftp.conn.files["mc/public/clients/index.html"][1]
        self.assertIn(b"Windows EXE", html)
        self.assertNotIn(b"password", html)
        self.assertNotIn(b"mysql", html.lower())


if __name__ == "__main__":
    unittest.main()
