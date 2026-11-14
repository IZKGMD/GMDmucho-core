"""Offline regression tests for the first-run 5v.pl archive installer."""
import importlib.util
import os
import zipfile
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "tools/release/5v-installer"
sys.path.insert(0, str(SRC))
from mucho5v import (InstallError, site_origin, valid_ftp_host, php_quote,
                     private_config, merge_rules, safe_relative, FormFields)
import INSTALL_5V
import RECOVER_ADMIN_5V


class DomainTests(unittest.TestCase):
    def test_only_exact_5v_https_roots(self):
        for good in ("https://gdps.5v.pl", "https://mymc-02.5v.pl/",
                     "https://x.5v.pl"):
            with self.subTest(good=good):
                self.assertEqual(site_origin(good).split("//")[1].split(".")[-2:], ["5v","pl"])
        for bad in ("https://5v.pl", "http://x.5v.pl",
                    "https://x.5v.pl.example.org",
                    "https://evil5v.pl", "https://x.5v.pl@evil.test",
                    "https://x.5v.pl/mc", "https://x.5v.pl:444",
                    "https://x.5v.pl:99999", "https://x.5v.pl?x=1"):
            with self.subTest(bad=bad), self.assertRaises(InstallError):
                site_origin(bad)
    def test_ftp_host_only_dns(self):
        self.assertEqual(valid_ftp_host("FTP.5v.pl"), "ftp.5v.pl")
        for invalid in ("https://ftp.5v.pl", "localhost", "1.2.3.4:21",
                        "ftp.5v.pl/path", "ftp.5v.pl\nx", ""):
            with self.subTest(host=invalid), self.assertRaises(InstallError):
                valid_ftp_host(invalid)


class EasyInstallerTests(unittest.TestCase):
    def test_quick_mode_skips_hosting_advanced_questions(self):
        prompts = []
        answers = {
            "Адрес сайта, например https://mygdps.5v.pl": "https://mine.5v.pl",
            "FTP сервер из кабинета 5v.pl": "ftp.5v.pl",
            "FTP логин": "u123",
            "Имя ПУСТОЙ базы MySQL": "database1",
            "Логин базы MySQL": "dbuser1",
            "E-mail владельца (для уведомлений и подтверждений)": "owner@example.com",
        }

        def provide(label, default=""):
            prompts.append(label)
            return answers.get(label, default or "Д")

        with patch.object(INSTALL_5V, "ask", side_effect=provide), \
             patch.object(INSTALL_5V, "secret",
                          side_effect=["ftp-pass", "database-pass11",
                                       "admin-password11", "admin-password11"]):
            config = INSTALL_5V.get_credentials()

        self.assertEqual(config["root"], "")
        self.assertEqual(config["mode"] if "mode" in config else config["secure"], True)
        self.assertEqual(config["mysqlhost"], "localhost")
        self.assertEqual(config["mysqlport"], 3306)
        self.assertEqual(config["admin"], "admin")
        self.assertFalse(any("Порт" in label or "Корневая папка" in label
                             or "Соединение" in label or "MySQL сервер" in label
                             for label in prompts))

    def test_advanced_mode_still_supports_nonstandard_hosting(self):
        prompts = []
        answers = {
            "Адрес сайта, например https://mygdps.5v.pl": "https://mine.5v.pl",
            "FTP сервер из кабинета 5v.pl": "ftp.5v.pl",
            "FTP логин": "u123",
            "Соединение [FTPS/FTP]": "FTPS",
            "Корневая папка сайта на FTP (Enter = авто)": "www",
            "MySQL сервер из кабинета 5v.pl": "mysql.5v.pl",
            "Порт MySQL": "3307",
            "Имя ПУСТОЙ базы MySQL": "database1",
            "Логин базы MySQL": "dbuser1",
            "E-mail владельца (для уведомлений и подтверждений)": "owner@example.com",
        }
        def provide(label, default=""):
            prompts.append(label)
            return answers.get(label, default or "Д")
        with patch.object(INSTALL_5V, "ask", side_effect=provide), \
             patch.object(INSTALL_5V, "secret",
                          side_effect=["ftp-pass", "database-pass11",
                                       "admin-password11", "admin-password11"]):
            config = INSTALL_5V.get_credentials(advanced=True)
        self.assertEqual(config["root"], "www")
        self.assertEqual(config["mysqlhost"], "mysql.5v.pl")
        self.assertEqual(config["mysqlport"], 3307)
        self.assertIn("Соединение [FTPS/FTP]", prompts)


class PhpConfigTests(unittest.TestCase):
    def test_php_escape_and_private_config_lint(self):
        self.assertEqual(php_quote("O'Reilly\\test"), "'O\\'Reilly\\\\test'")
        code = private_config("https://mygdps.5v.pl", "localhost", 3306,
                              "gdps_db", "gdps_user", "Secret'\\pass99")
        self.assertIn(b"enable_social' => false", code)
        self.assertIn(b"php", code)
        self.assertNotIn(b"enable_accounts' => true", code)
        with tempfile.TemporaryDirectory() as d:
            file = Path(d) / "config.php"
            file.write_bytes(code)
            check = subprocess.run(["php","-l",str(file)], text=True,
                                   capture_output=True)
            self.assertEqual(check.returncode, 0, check.stderr)
    def test_private_bad_input(self):
        for db in ("db;DROP", "db name", ""):
            with self.subTest(db=db), self.assertRaises(InstallError):
                private_config("https://x.5v.pl","localhost",3306,db,"user","secret123")
        with self.assertRaises(InstallError):
            php_quote("bad\nvalue")


class ApacheTests(unittest.TestCase):
    def setUp(self):
        script = (ROOT / "tools/release/build-php7-native.sh").read_text()
        found = re.search(
            r"cat > \"\$STAGE/5V_ROOT_REWRITE_RULES\.txt\" <<'APACHE'\n(.*?)\nAPACHE",script,re.S)
        self.assertIsNotNone(found)
        self.rules = found.group(1).encode()
    def test_merge_is_idempotence_guarded_and_preserves_existing(self):
        existing = b"RewriteEngine On\nRewriteRule ^other$ index.php [L]\n"
        result = merge_rules(existing, self.rules)
        self.assertIn(existing, result)
        self.assertIn(b"RewriteRule ^(?:a|database|accounts|api)", result)
        self.assertIn(b"# BEGIN MUCHOCORE 5V.PL ROUTES", result)
        with self.assertRaises(InstallError):
            merge_rules(result, self.rules)
    def test_reject_malformed_package_rules(self):
        with self.assertRaises(InstallError):
            merge_rules(b"", b"RewriteRule ^wrong$ bad.php [L]")
        with self.assertRaises(InstallError):
            merge_rules(b"\0", self.rules)
    def test_reject_path_escape(self):
        for path in ("../config", "/etc/passwd", "a//b", "a\\b", "a/./b"):
            with self.subTest(path=path), self.assertRaises(InstallError):
                safe_relative(path)


class RecoveryTests(unittest.TestCase):
    def test_ephemeral_recovery_only_targets_existing_owner(self):
        template = (SRC/"RECOVERY_HANDLER.php.tpl").read_text()
        self.assertIn("role='owner'", template)
        self.assertIn("FOR UPDATE", template)
        self.assertIn("hash_equals('__HASH__'", template)
        self.assertIn("time() > __EXPIRES__", template)
        self.assertIn("@unlink(__FILE__)", template)
        self.assertIn("auth_version=auth_version+1", template)
        self.assertIn("php7_admin_passkeys SET is_active=0", template)
        self.assertNotIn("CREATE TABLE accounts", template)
        self.assertNotIn("TRUNCATE", template)
        self.assertNotIn("DROP TABLE", template)
        rendered = (template.replace("__HASH__", "a"*64)
                            .replace("__EXPIRES__", "1800000000")
                            .replace("__DOMAIN__", "gdps.5v.pl"))
        with tempfile.TemporaryDirectory() as d:
            target=Path(d)/"recover.php"
            target.write_text(rendered)
            check = subprocess.run(["php","-l",str(target)],capture_output=True,text=True)
            self.assertEqual(check.returncode,0,check.stderr)
    def test_recovery_rejects_weak_credentials_without_network(self):
        with self.assertRaises(InstallError):
            RECOVER_ADMIN_5V.recover("https://not5v.test","ftp.5v.pl",
                                    "user","pass","owner","secure-new-password")


class ArchiveTests(unittest.TestCase):
    def test_launchers_and_recovery_are_in_release_builder(self):
        builder = (ROOT/"tools/release/build-php7-native.sh").read_text()
        for name in ("INSTALL_5V.py", "RECOVER_ADMIN_5V.py", "mucho5v.py",
                     "START_INSTALL_WINDOWS.cmd", "START_RECOVERY_WINDOWS.cmd",
                     "RECOVERY_HANDLER.php.tpl", "README_RU.txt"):
            self.assertIn(name,builder)
        self.assertIn('forbidden in mc/config/php7-local.php',builder)

    def test_web_deploy_archive_matches_worker_strict_allowlist(self):
        """Build the real browser-deployment ZIP; no local launchers in FTP root."""
        with tempfile.TemporaryDirectory() as folder:
            archive = Path(folder) / "5v-web-deploy.zip"
            env = os.environ.copy()
            env.update(MUCHO_5V_WEB_DEPLOY="1", OUTPUT_PATH=str(archive))
            process = subprocess.run(
                ["bash", str(ROOT / "tools/release/build-php7-native.sh")],
                cwd=ROOT, env=env, capture_output=True, text=True, timeout=180,
            )
            self.assertEqual(process.returncode, 0, process.stderr[-3000:])
            self.assertTrue(archive.is_file())
            with zipfile.ZipFile(archive) as bundle:
                names = bundle.namelist()
                self.assertEqual(len(names), len(set(names)))
                self.assertGreater(len(names), 20)
                for name in names:
                    with self.subTest(entry=name):
                        self.assertTrue(
                            name.startswith("mc/") or name in (
                                "5V_ROOT_REWRITE_RULES.txt", "START_HERE_SHARED.txt"),
                            name,
                        )
                        self.assertNotIn(chr(92), name)
                        self.assertNotIn(chr(0), name)
                        self.assertFalse(name.startswith("/"))
                        self.assertFalse(any(p in (".", "..") for p in name.split("/")))
                for required in (
                    "mc/admin.php", "mc/public/php7.php", "mc/public/php7-admin.php",
                    "mc/compat/php7/runtime.php", "mc/compat/php7/core/sources.json",
                    "mc/.htaccess", "5V_ROOT_REWRITE_RULES.txt",
                ):
                    self.assertIn(required, names)
                for local_only in (
                    "INSTALL_5V.py", "GUI_SETUP_5V.py",
                    "RECOVERY_HANDLER.php.tpl", "README_RU.txt",
                    "gd_windows_patcher.py",
                ):
                    self.assertNotIn(local_only, names)

    def test_owner_bootstrap_requires_form_token(self):
        form = b'<form><input type="hidden" name="csrf" value="'+b'a'*48+\
               b'"><input type="hidden" name="action" value="bootstrap"></form>'
        self.assertEqual(INSTALL_5V.csrf_from(form, "bootstrap"), "a"*48)
        with self.assertRaises(InstallError):
            INSTALL_5V.csrf_from(form, "core-upgrade")


if __name__ == "__main__":
    unittest.main()
