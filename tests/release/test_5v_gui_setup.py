"""Contract tests for the standalone MuchoCore 5v.pl Windows wizard."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
GUI = ROOT / "tools/release/5v-installer/GUI_SETUP_5V.py"
INSTALL = ROOT / "tools/release/5v-installer/INSTALL_5V.py"
PUBLISH = ROOT / "tools/release/5v-installer/PUBLISH_CLIENTS_5V.py"
BUILDER = ROOT / "tools/release/build-php7-native.sh"
WORKFLOW = ROOT / ".github/workflows/php7-preview-archives.yml"


class GuiSetupContract(unittest.TestCase):
    def test_gui_is_windows_onefile_entrypoint_with_offline_self_test(self):
        source = GUI.read_text(encoding="utf-8")
        self.assertIn("def _self_test()", source)
        self.assertIn("import RECOVER_ADMIN_5V", source)
        self.assertIn("self.open_recovery", source)
        self.assertIn("RECOVER_ADMIN_5V.recover(", source)
        self.assertIn('if "--self-test" in sys.argv', source)
        self.assertIn('getattr(sys, "_MEIPASS"', source)
        self.assertIn("tkinter", source)
        self.assertIn("messagebox.askyesno", source)
        self.assertIn("threading.Thread", source)
        self.assertIn("def on_close(self):", source)
        self.assertIn("if len(matches) > 1:", source)
        self.assertNotIn("pip install", source.lower())
        self.assertNotIn("subprocess.run", source)

    def test_gui_requires_explicit_empty_database_confirmation(self):
        source = GUI.read_text(encoding="utf-8")
        self.assertIn("self.empty_db = tk.BooleanVar(value=False)", source)
        self.assertIn('if not self.empty_db.get():', source)
        self.assertIn("Подтверди, что база данных действительно пустая", source)

    def test_client_publication_is_opt_in_and_keeps_exe_local_without_rights(self):
        source = GUI.read_text(encoding="utf-8")
        self.assertIn("self.rights = tk.BooleanVar(value=False)", source)
        self.assertIn("patch_windows(exe_source", source)
        self.assertIn("if redistribution_rights:", source)
        self.assertIn("Публичная загрузка EXE отключена", source)
        self.assertIn("APK пропущен: публичная публикация требует подтверждения прав", source)

    def test_installer_has_frozen_resource_and_output_paths(self):
        source = INSTALL.read_text(encoding="utf-8")
        self.assertIn('Path(getattr(sys, "_MEIPASS"', source)
        self.assertIn('getattr(sys, "frozen", False)', source)
        self.assertIn("def perform_install(cfg, *, prepare_clients: bool = True):", source)
        self.assertIn('cfg["_resolved_root"] = root', source)
        self.assertIn("if prepare_clients:", source)
        self.assertIn("report[\"clients_status\"] = \"deferred\"", source)

    def test_client_output_is_written_beside_frozen_exe(self):
        source = PUBLISH.read_text(encoding="utf-8")
        self.assertIn('getattr(sys, "frozen", False)', source)
        self.assertIn('Path(sys.executable).resolve().parent', source)

    def test_php7_zip_includes_gui_source_but_no_original_game_binary(self):
        source = BUILDER.read_text(encoding="utf-8")
        self.assertIn("GUI_SETUP_5V.py", source)
        self.assertIn("START_GUI_WINDOWS.cmd", source)
        self.assertIn("grep -Ei '\\.(exe|apk)$'", source)
        self.assertNotIn('cp "$ROOT/GeometryDash.exe"', source)
        self.assertNotIn('cp "$ROOT/GeometryDash.apk"', source)

    def test_windows_job_builds_and_smoke_tests_onefile_exe(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("windows-setup:", source)
        self.assertIn("runs-on: windows-latest", source)
        self.assertIn("--onefile", source)
        self.assertIn("--windowed", source)
        self.assertIn("--add-data", source)
        self.assertIn("MuchoCore-Setup.exe --self-test", source)
        self.assertIn("--hidden-import RECOVER_ADMIN_5V", source)
        self.assertIn("RECOVERY_HANDLER.php.tpl;.", source)
        self.assertIn("MuchoCore-5V-Setup-Windows-x64", source)
        self.assertIn("release:", source)
        self.assertIn("softprops/action-gh-release@v2", source)
        self.assertIn("files: dist/MuchoCore-Setup.exe", source)


if __name__ == "__main__":
    unittest.main()
