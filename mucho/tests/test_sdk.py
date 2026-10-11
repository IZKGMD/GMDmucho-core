import json
from pathlib import Path
import shutil
import sys
import tempfile
import types
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "sdk"))
sys.dont_write_bytecode = True


class Native(types.ModuleType):
    def __init__(self):
        super().__init__("_mucho_native")
        self.active = {}
        self.commands = []
        self.logs = []
        self.snapshot = []

    def activate(self, owner, generation):
        self.active[owner] = generation
        self.commands = [c for c in self.commands if c[0] != owner]

    def command(self, owner, generation, scene, operation, raw):
        assert self.active[owner] == generation
        self.commands.append((owner, generation, scene, operation, json.loads(raw)))

    def log(self, owner, text):
        self.logs.append((owner, text))

    def publish(self, raw):
        self.snapshot = json.loads(raw)


native = Native()
sys.modules[native.__name__] = native
from mucho._manager import Manager
from mucho import ui, effects


class SDKTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="mucho-python-")
        self.root = Path(self.temp.name)
        native.active.clear()
        native.commands.clear()
        native.logs.clear()
        self.manager = None

    def tearDown(self):
        if self.manager:
            self.manager.shutdown()
        self.temp.cleanup()

    def plugin(self, key="example", code="", **metadata):
        path = self.root / key
        path.mkdir(exist_ok=True)
        manifest = {"id": key, "name": key, "version": "1.0.0", "api": 1, "enabled": True}
        manifest.update(metadata)
        (path / "manifest.json").write_text(json.dumps(manifest))
        (path / "plugin.py").write_text(code)
        return path

    def load(self):
        self.manager = Manager(self.root)
        return self.manager

    def test_real_event_and_native_commands(self):
        self.plugin(code='from mucho import events,effects\n@events.on("level.start")\ndef start(e): effects.pulse("#00FF00", .2)\n')
        manager = self.load()
        manager.dispatch("level.start", '{"level_id": 9}', 44)
        self.assertEqual(native.commands[0][2:4], (44, "pulse"))
        self.assertEqual(manager.plugins["example"].calls, 1)

    def test_button_callbacks_and_stale_generations(self):
        self.plugin(code='from mucho import ui\nui.button("hello", "Hello", lambda: ui.notice("native"))\n')
        manager = self.load()
        old = manager.plugins["example"].generation
        payload = {"owner": "example", "id": "hello", "generation": old}
        manager.dispatch("ui.button", json.dumps(payload), 3)
        self.assertEqual(native.commands[-1][-1]["message"], "native")
        manager.control('{"operation":"reload","id":"example"}')
        self.assertGreater(manager.plugins["example"].generation, old)
        manager.dispatch("ui.button", json.dumps(payload), 3)
        self.assertEqual(native.commands, [])

    def test_failed_import_does_not_keep_commands_or_handlers(self):
        self.plugin(code='from mucho import ui\nui.notice("stale")\nraise RuntimeError("bad import")\n')
        plugin = self.load().plugins["example"]
        self.assertEqual(plugin.status, "error")
        self.assertEqual(native.commands, [])
        self.assertEqual(plugin.handlers, {})

    def test_callback_exception_isolation_and_quarantine(self):
        code = 'from mucho import events\n@events.on("level.start")\ndef start(e): raise ValueError("bad")\n'
        self.plugin("bad", code)
        self.plugin("good", 'from mucho import events,ui\n@events.on("level.start")\ndef start(e): ui.notice("good")\n')
        manager = self.load()
        for _ in range(3):
            manager.dispatch("level.start", "{}", 1)
        self.assertEqual(manager.plugins["bad"].status, "error")
        self.assertEqual(manager.plugins["good"].status, "loaded")
        self.assertEqual(len(native.commands), 3)

    def test_python_loop_budget(self):
        self.plugin(code='from mucho import events\n@events.on("level.start")\ndef start(e):\n while True: pass\n')
        manager = self.load()
        manager.dispatch("level.start", "{}", 1)
        self.assertEqual(manager.plugins["example"].status, "error")
        self.assertIn("BudgetExceeded", manager.plugins["example"].error)

    def test_failed_dependency_blocks_transitive_dependents(self):
        self.plugin("base", 'from mucho import events\n@events.on("level.start")\ndef start(e): raise RuntimeError("failed")\n')
        self.plugin("middle", dependencies={"base": "1.0.0"})
        self.plugin("last", dependencies={"middle": "1.0.0"})
        manager = self.load()
        for _ in range(3):
            manager.dispatch("level.start", "{}", 1)
        self.assertEqual(manager.plugins["base"].status, "error")
        self.assertEqual(manager.plugins["middle"].status, "blocked")
        self.assertEqual(manager.plugins["last"].status, "blocked")
        self.assertIsNone(manager.plugins["last"].module)
        manager.control('{"operation":"reload","id":"base"}')
        self.assertTrue(all(p.status == "loaded" for p in manager.plugins.values()))

    def test_import_loop_budget(self):
        self.plugin(code='while True: pass\n')
        plugin = self.load().plugins["example"]
        self.assertEqual(plugin.status, "error")

    def test_dependencies_order_disable_and_restore(self):
        self.plugin("aaa", dependencies={"zzz": "1.0.0"})
        self.plugin("zzz")
        manager = self.load()
        self.assertEqual(manager.plugins["aaa"].status, "loaded")
        self.assertLess(manager.plugins["zzz"].generation, manager.plugins["aaa"].generation)
        manager.control('{"operation":"disable","id":"zzz"}')
        self.assertEqual(manager.plugins["aaa"].status, "blocked")
        self.assertEqual(manager.plugins["zzz"].status, "disabled")
        manager.control('{"operation":"enable","id":"zzz"}')
        self.assertEqual(manager.plugins["aaa"].status, "loaded")
        self.assertTrue(json.loads((self.root / ".state.json").read_text())["zzz"])

    def test_dependency_cycle(self):
        self.plugin("aaa", dependencies={"bbb": "1.0.0"})
        self.plugin("bbb", dependencies={"aaa": "1.0.0"})
        manager = self.load()
        self.assertTrue(all(p.status == "blocked" for p in manager.plugins.values()))

    def test_missing_old_or_invalid_dependency(self):
        self.plugin("aaa", dependencies={"bbb": "2.0.0"})
        self.plugin("bbb", version="invalid")
        self.assertEqual(self.load().plugins["aaa"].status, "blocked")

    def test_default_disabled_and_reject_string_boolean(self):
        path = self.plugin()
        data = json.loads((path / "manifest.json").read_text())
        del data["enabled"]
        (path / "manifest.json").write_text(json.dumps(data))
        self.assertEqual(self.load().plugins["example"].status, "disabled")
        data["enabled"] = "true"
        (path / "manifest.json").write_text(json.dumps(data))
        self.manager.scan()
        self.assertEqual(self.manager.plugins["example"].status, "invalid")

    def test_bad_manifests_and_version_guards(self):
        cases = [{"api": 2}, {"id": "another"}, {"min_loader_version": "9.0.0"}, {"api": True}, {"version": "01.0.0"}]
        for n, case in enumerate(cases):
            self.plugin(f"invalid{n}", **case)
        manager = self.load()
        self.assertTrue(all(p.status == "invalid" for p in manager.plugins.values()))
        manager.scan()

    def test_rescan_sees_added_and_removed_plugins(self):
        manager = self.load()
        self.plugin()
        manager.control('{"operation":"rescan"}')
        self.assertEqual(manager.plugins["example"].status, "loaded")
        shutil.rmtree(self.root / "example")
        manager.control('{"operation":"rescan"}')
        self.assertEqual(manager.plugins, {})

    def test_relative_helper_imports_reload(self):
        path = self.plugin(code='from .helper import text\nfrom mucho import ui\nui.button("hi", text, lambda:None)\n')
        (path / "helper.py").write_text('text = "Hello"\n')
        manager = self.load()
        self.assertEqual(native.snapshot[0]["buttons"][0]["title"], "Hello")
        (path / "helper.py").write_text('text = "World"\n')
        manager.control('{"operation":"reload","id":"example"}')
        self.assertEqual(native.snapshot[0]["buttons"][0]["title"], "World")

    def test_stale_bytecode_cannot_hide_edited_source(self):
        import os
        import py_compile
        path = self.plugin(code='from .helper import text\nfrom mucho import ui\nui.button("hi",text,lambda:None)\n')
        helper = path / "helper.py"
        helper.write_text('text = "Hello"\n')
        stamp = helper.stat().st_mtime
        py_compile.compile(str(helper))
        helper.write_text('text = "World"\n')
        os.utime(helper, (stamp, stamp))
        self.load()
        self.assertEqual(native.snapshot[0]["buttons"][0]["title"], "World")

    def test_removed_button_cannot_be_called_after_reload(self):
        path = self.plugin(code='from mucho import ui\nui.button("hi","Hi",lambda:ui.notice("bad"))\n')
        manager = self.load()
        (path / "plugin.py").write_text('from mucho import log\nlog("new version")\n')
        manager.control('{"operation":"reload","id":"example"}')
        manager.dispatch("ui.button", json.dumps({"owner":"example","generation":manager.plugins["example"].generation,"id":"hi"}))
        self.assertEqual(native.commands, [])

    def test_symlinked_entry_is_rejected(self):
        path = self.plugin()
        (self.root / "outside.py").write_text('raise RuntimeError("must not run")')
        (path / "plugin.py").unlink()
        (path / "plugin.py").symlink_to(self.root / "outside.py")
        self.assertEqual(self.load().plugins["example"].status, "invalid")

    def test_events_are_immutable(self):
        self.plugin(code='from mucho import events\n@events.on("level.start")\ndef event(e): e["level_id"]=1\n')
        manager = self.load()
        manager.dispatch("level.start", '{"level_id":2}')
        self.assertIn("TypeError", manager.plugins["example"].error)

    def test_bad_state_is_diagnosed(self):
        (self.root / ".state.json").write_text('{"example":"true"}')
        self.plugin()
        self.assertEqual(self.load().plugins["example"].status, "loaded")
        self.assertTrue(any("Ignoring invalid saved state" in text for _, text in native.logs))

    def test_api_input_validation_and_ownership(self):
        with self.assertRaises(RuntimeError): ui.notice("hello")
        for color, duration in [("bad", .2), ("#FFFFFF", float("nan")), ("#FFFFFF", True)]:
            with self.assertRaises(ValueError): effects.pulse(color, duration)

    def test_script_exception_cannot_exit_runtime(self):
        self.plugin("aaa", "raise SystemExit(4)")
        self.plugin("bbb")
        manager = self.load()
        self.assertEqual(manager.plugins["aaa"].status, "error")
        self.assertEqual(manager.plugins["bbb"].status, "loaded")

    def test_unload_cleans_module_namespace(self):
        self.plugin(code='from . import helper\n')
        (self.root / "example" / "helper.py").write_text('x=1\n')
        manager = self.load()
        prefix = manager.plugins["example"].module.__name__
        manager.control('{"operation":"disable","id":"example"}')
        self.assertFalse(any(k == prefix or k.startswith(prefix + ".") for k in sys.modules))


if __name__ == "__main__":
    unittest.main(verbosity=2)
