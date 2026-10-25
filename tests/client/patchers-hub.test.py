#!/usr/bin/env python3
"""Repository-wide entry-point contract: all patcher launchers stay discoverable."""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PatchersHubTest(unittest.TestCase):
    def test_entry_points_link_to_canonical_engines(self):
        files = [
            "patchers/README.md",
            "patchers/START-PATCHER.cmd",
            "patchers/windows/START-WINDOWS.cmd",
            "patchers/windows/README.md",
            "patchers/android/OPEN-ANDROID.cmd",
            "patchers/android/open-android.ps1",
            "patchers/android/README.md",
            "patchers/advanced/README.md",
            "patchers/shared-hosting/README.md",
            "tools/client/client-patcher.ps1",
            "tools/client/client-patch.py",
            "src/Client/AndroidClientPatcher.php",
            "bin/mucho-patch-gd2209.php",
        ]
        for name in files:
            with self.subTest(file=name):
                self.assertTrue((ROOT / name).is_file(), name)

    def test_windows_launcher_is_not_a_shadow_patcher(self):
        launcher = (ROOT / "patchers/windows/START-WINDOWS.cmd").read_text()
        self.assertIn("tools\\client\\client-patcher.ps1", launcher)
        self.assertIn("tools\\client\\client-patch.py", launcher)
        self.assertIn('-ServerUrl ""', launcher)
        self.assertNotIn("GeometryDash.exe.bak", launcher)
        self.assertNotIn("ftp", launcher.lower())

    def test_android_uses_authenticated_admin_instead_of_unsigned_apk(self):
        helper = (ROOT / "patchers/android/open-android.ps1").read_text()
        self.assertIn("/admin/?page=clientpatcher", helper)
        self.assertIn("$parsed.Scheme -ne 'https'", helper)
        self.assertNotIn("ftp://", helper.lower())
        self.assertNotIn("Get-Credential", helper)
        self.assertNotIn("Invoke-WebRequest", helper)

    def test_readme_links_and_engine_hardening(self):
        hub = (ROOT / "patchers/README.md").read_text()
        root = (ROOT / "README.md").read_text()
        python = (ROOT / "tools/client/client-patch.py").read_text()
        for word in ["windows/", "android/", "advanced/", "shared-hosting/"]:
            self.assertIn(word, hub)
        self.assertIn("patchers/README.md", root)
        self.assertIn("GD2209_SHA256_OCT19", python)
        self.assertIn("GD2209_BUILDS", python)
        self.assertNotIn("linasgdpsprivatese", hub)
        self.assertIn("Unsupported or modified GD 2.209 EXE", python)


if __name__ == "__main__":
    unittest.main()
