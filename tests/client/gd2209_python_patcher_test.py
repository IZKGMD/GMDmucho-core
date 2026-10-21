#!/usr/bin/env python3
"""Verify the GD 2.209 Python patcher reproduces the safe PHP .rdata approach."""
from __future__ import annotations

import importlib.util
import hashlib
import shutil
import subprocess
import tempfile
import struct
import sys
import unittest
from unittest import mock
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PATCHER = ROOT / "tools/client/client-patch.py"

spec = importlib.util.spec_from_file_location("muchocore_gd2209_patcher", PATCHER)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

OLD_URLS = (
    b"https://www.boomlings.com/database/getGJLevels21.php",
    b"https://www.boomlings.com/databas/checkIfServerOnline.php",
)


def fixture():
    binary = bytearray(0x2000)
    binary[:2] = b"MZ"
    struct.pack_into("<I", binary, 0x3c, 0x80)
    binary[0x80:0x84] = b"PE\0\0"
    struct.pack_into("<H", binary, 0x84, 0x8664)
    struct.pack_into("<H", binary, 0x86, 1)
    struct.pack_into("<H", binary, 0x94, 0xF0)
    section = 0x80 + 24 + 0xF0
    binary[section:section+8] = b".rdata\0\0"
    struct.pack_into("<I", binary, section+16, 0x1000)
    struct.pack_into("<I", binary, section+20, 0x400)
    binary[0x480:0x480+len(OLD_URLS[0])] = OLD_URLS[0]
    binary[0x480+len(OLD_URLS[0])] = 0
    binary[0x580:0x580+len(OLD_URLS[1])] = OLD_URLS[1]
    binary[0x580+len(OLD_URLS[1])] = 0
    # Preserve non-API links even if the host is Boomlings.
    binary[0x680:0x680+len(b"https://www.boomlings.com/FAQ")] = b"https://www.boomlings.com/FAQ"
    return bytes(binary)


class GD2209PatcherTest(unittest.TestCase):
    def test_complete_url_replacement_and_malformed_path_repair(self):
        source = fixture()
        patched, changes = module.patch_gd2209_exact(
            source, "https://muchogdps.space")
        self.assertEqual(len(source), len(patched))
        self.assertEqual(sum(item.count for item in changes), 2)
        self.assertIn(b"https://muchogdps.space/database/getGJLevels21.php", patched)
        self.assertIn(b"https://muchogdps.space/database/checkIfServerOnline.php", patched)
        self.assertIn(b"https://www.boomlings.com/FAQ", patched)
        self.assertNotIn(b"https://www.boomlings.com/database/", patched)
        self.assertEqual(source[:0x400], patched[:0x400])
        self.assertEqual(source[0x1400:], patched[0x1400:])

    @unittest.skipUnless(shutil.which("php"), "PHP CLI needed for native parity test")
    def test_php_web_patcher_and_python_patcher_produce_identical_bytes(self):
        source = fixture()
        python_bytes, _ = module.patch_gd2209_exact(
            source, "https://muchogdps.space"
        )
        with tempfile.TemporaryDirectory() as temp:
            original = Path(temp) / "source.exe"
            result = Path(temp) / "result.exe"
            original.write_bytes(source)
            code = (
                'require $argv[1]; '
                '$source=file_get_contents($argv[2]); '
                '[$patched]=\\MuchoCore\\Client\\Gd2209Binary::patchBytes('
                '$source, "muchogdps.space"); '
                'file_put_contents($argv[3], $patched);'
            )
            proc = subprocess.run(
                ["php", "-r", code,
                 str(ROOT / "src/Client/Gd2209Binary.php"),
                 str(original), str(result)],
                capture_output=True, text=True,
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(python_bytes, result.read_bytes())

    @unittest.skipUnless(shutil.which("php"), "PHP CLI needed for native parity test")
    def test_long_5v_shared_host_uses_short_alias_and_matches_php(self):
        source = fixture()
        host = "muchocoretest.5v.pl"
        python_bytes, _ = module.patch_gd2209_exact(
            source, "https://" + host
        )
        self.assertEqual(len(python_bytes), len(source))
        self.assertIn(
            b"https://muchocoretest.5v.pl/a/checkIfServerOnline.php\x00",
            python_bytes
        )
        self.assertIn(
            b"https://muchocoretest.5v.pl/a/getGJLevels21.php\x00",
            python_bytes
        )
        with tempfile.TemporaryDirectory() as temp:
            original = Path(temp) / "source.exe"
            output = Path(temp) / "patched.exe"
            original.write_bytes(source)
            script = (
                'require $argv[1];'
                '$input=file_get_contents($argv[2]);'
                '[$patched]=\MuchoCore\Client\Gd2209Binary::patchBytes('
                '$input, "muchocoretest.5v.pl");'
                'file_put_contents($argv[3], $patched);'
            )
            result = subprocess.run(
                ["php", "-r", script,
                 str(ROOT / "src/Client/Gd2209Binary.php"),
                 str(original), str(output)],
                capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(python_bytes, output.read_bytes())

    @unittest.skipUnless(shutil.which("php"), "PHP needed for byte parity test")
    def test_very_long_5v_host_uses_root_alias_and_matches_php(self):
        source = fixture()
        host = "linasgdpsprivatese.5v.pl"
        patched, stats = module.patch_gd2209_exact(
            source, "https://" + host
        )
        self.assertEqual(len(source), len(patched))
        self.assertEqual(sum(x.count for x in stats), 2)
        self.assertIn(
            b"https://linasgdpsprivatese.5v.pl/a/getGJLevels21.php\x00",
            patched,
        )
        self.assertIn(
            b"https://linasgdpsprivatese.5v.pl/checkIfServerOnline.php\x00",
            patched,
        )
        with tempfile.TemporaryDirectory() as d:
            original = Path(d) / "original.exe"
            output = Path(d) / "patched.exe"
            original.write_bytes(source)
            code = (
                'require $argv[1];'
                '$source=file_get_contents($argv[2]);'
                '[$patched]=\\MuchoCore\\Client\\Gd2209Binary::patchBytes('
                '$source,"linasgdpsprivatese.5v.pl");'
                'file_put_contents($argv[3],$patched);'
            )
            result = subprocess.run(
                ["php", "-r", code,
                 str(ROOT / "src/Client/Gd2209Binary.php"),
                 str(original), str(output)],
                text=True, capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(output.read_bytes(), patched)

    def test_unpatchably_long_host_fails_without_partial_output(self):
        with self.assertRaisesRegex(ValueError, "shorter hostname"):
            module.patch_gd2209_exact(
                fixture(), "https://linasgdpsprivatese555.5v.pl"
            )

    def test_full_url_is_never_truncated_at_hostname(self):
        source = fixture()
        patched, _ = module.patch_gd2209_exact(
            source, "https://a.5v.pl")
        self.assertIn(
            b"https://a.5v.pl/database/getGJLevels21.php\x00", patched
        )
        self.assertIn(
            b"https://a.5v.pl/database/checkIfServerOnline.php\x00", patched
        )

    def test_disallows_http_or_long_host_for_2209(self):
        with self.assertRaises(ValueError):
            module.patch_gd2209_exact(fixture(), "http://muchogdps.space")
        with self.assertRaises(ValueError):
            module.patch_gd2209_exact(
                fixture(), "https://" + "x" * 80 + ".example.net")

    def test_oct19_original_uses_exact_engine_not_legacy_fallback(self):
        # A synthetic file cannot satisfy a real SHA-256. Mock only build
        # identification, then assert dispatch selects the exact PE engine.
        source = b"x" * 13178880
        fake = [module.PatchStat("GD 2.209", 113, "https://gdps.example.com")]
        with mock.patch.object(
            module, "sha256", return_value=module.GD2209_SHA256_OCT19
        ), mock.patch.object(
            module, "patch_gd2209_exact", return_value=(source, fake)
        ) as exact:
            patched, stats = module.patch_client(source, "https://gdps.example.com")
        exact.assert_called_once()
        self.assertEqual(patched, source)
        self.assertEqual(sum(x.count for x in stats), 113)

    def test_oct19_wrong_endpoint_count_is_rejected(self):
        source = b"x" * 13178880
        fake = [module.PatchStat("GD 2.209", 112, "https://gdps.example.com")]
        with mock.patch.object(
            module, "sha256", return_value=module.GD2209_SHA256_OCT19
        ), mock.patch.object(
            module, "patch_gd2209_exact", return_value=(source, fake)
        ):
            with self.assertRaisesRegex(ValueError, "expected 113"):
                module.patch_client(source, "https://gdps.example.com")

    def test_unknown_2209_never_falls_back_to_legacy_patcher(self):
        source = bytearray(10 * 1024 * 1024)
        source[:2] = b"MZ"
        source[100:122] = b"getGJNotifications.php"
        source[200:222] = b"getGJThreadInvites.php"
        with self.assertRaisesRegex(ValueError, "Unknown GD 2.209 build"):
            module.patch_client(bytes(source), "https://gdps.example.com")

    def test_exact_sha_ensures_custom_builds_use_legacy_route(self):
        self.assertNotEqual(
            hashlib.sha256(fixture()).hexdigest(), module.GD2209_SHA256
        )


if __name__ == "__main__":
    unittest.main()
