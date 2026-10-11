"""Single-worker plugin lifecycle. Native mods remain under Geode's loader."""
import importlib.util
import importlib.abc
import importlib.machinery
import json
import os
from pathlib import Path
import re
import sys
import time
from dataclasses import dataclass, field
from types import MappingProxyType
import _mucho_native as native
from . import _api

_VERSION = re.compile(r"^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")
_SDK_VERSION = (0, 1, 0)


class _SourceLoader(importlib.machinery.SourceFileLoader):
    def get_code(self, fullname):
        # Compile current source even when an old .pyc has a matching timestamp/size.
        path = Path(self.path)
        if path.is_symlink() or path.stat().st_size > 524288:
            raise ValueError("Symlink or oversized Python source")
        return self.source_to_code(path.read_bytes(), self.path)


class _SourceFinder(importlib.abc.MetaPathFinder):
    def __init__(self):
        self.roots = {}

    def find_spec(self, fullname, path=None, target=None):
        prefix, _, suffix = fullname.partition(".")
        root = self.roots.get(prefix)
        if root is None or not suffix:
            return None
        relative = root.joinpath(*suffix.split("."))
        package = relative / "__init__.py"
        source = package if package.is_file() else relative.with_suffix(".py")
        if not source.is_file():
            return None
        return importlib.util.spec_from_file_location(fullname, source,
            loader=_SourceLoader(fullname, str(source)),
            submodule_search_locations=[str(relative)] if package.is_file() else None)


class BudgetExceeded(RuntimeError):
    pass


def _version(value):
    if not isinstance(value, str) or len(value) > 32 or not _VERSION.fullmatch(value):
        raise ValueError("Version must be MAJOR.MINOR.PATCH")
    return tuple(map(int, value.split(".")))


def _read(path, limit=32768):
    if path.is_symlink() or path.stat().st_size > limit:
        raise ValueError("Symlink or oversized file")
    return json.loads(path.read_text(encoding="utf-8"))


@dataclass
class Plugin:
    id: str
    path: Path
    name: str = ""
    version: str = "0.0.0"
    enabled: bool = False
    dependencies: dict = field(default_factory=dict)
    status: str = "disabled"
    error: str = ""
    generation: int = 0
    module: object = None
    handlers: dict = field(default_factory=dict)
    buttons: dict = field(default_factory=dict)
    calls: int = 0
    failures: int = 0
    last_ms: float = 0


class Manager:
    def __init__(self, root):
        self.root = Path(root)
        self.root.mkdir(parents=True, exist_ok=True)
        self.plugins = {}
        self.serial = 0
        self.state = {}
        self.finder = _SourceFinder()
        sys.meta_path.insert(0, self.finder)
        try:
            state = _read(self.root / ".state.json")
            if not isinstance(state, dict) or not all(_api._id.fullmatch(k) and type(v) is bool for k, v in state.items()):
                raise ValueError("Invalid saved plugin state")
            self.state = state
        except FileNotFoundError:
            pass
        except Exception as error:
            native.log("runtime", "Ignoring invalid saved state: " + str(error))
        self.scan()

    def _next(self, plugin):
        self.serial += 1
        plugin.generation = self.serial
        if _api._id.fullmatch(plugin.id):
            native.activate(plugin.id, plugin.generation)

    def scan(self):
        for plugin in list(self.plugins.values()):
            self._unload(plugin)
        self.plugins = {}
        paths = sorted(p for p in self.root.iterdir() if p.is_dir() and not p.name.startswith("."))
        if len(paths) > 128:
            native.log("runtime", "Only the first 128 plugin folders are inspected")
        for path in paths[:128]:
            plugin = Plugin(path.name, path, name=path.name)
            try:
                if path.is_symlink() or not _api._id.fullmatch(path.name):
                    raise ValueError("Invalid plugin folder")
                manifest = _read(path / "manifest.json")
                if not isinstance(manifest, dict):
                    raise ValueError("Manifest must be an object")
                if manifest.get("id") != path.name:
                    raise ValueError("Plugin ID must match its folder")
                if type(manifest.get("api")) is not int or manifest["api"] != 1:
                    raise ValueError("Unsupported Mucho Python API")
                plugin.name = _api._text(manifest.get("name"), 64)
                plugin.version = manifest.get("version")
                _version(plugin.version)
                minimum = _version(manifest.get("min_loader_version", "0.1.0"))
                maximum = _version(manifest.get("max_loader_version", "999.0.0"))
                if not minimum <= _SDK_VERSION <= maximum:
                    raise ValueError("Incompatible MuchoLoader version")
                enabled = manifest.get("enabled", False)
                if type(enabled) is not bool:
                    raise ValueError("enabled must be boolean")
                plugin.enabled = self.state.get(plugin.id, enabled)
                deps = manifest.get("dependencies", {})
                if not isinstance(deps, dict) or len(deps) > 16:
                    raise ValueError("dependencies must be an object with at most 16 entries")
                for dep, version in deps.items():
                    if not _api._id.fullmatch(dep) or dep == plugin.id:
                        raise ValueError("Invalid dependency ID")
                    plugin.dependencies[dep] = _version(version)
                entry = path / "plugin.py"
                if not entry.is_file() or entry.is_symlink() or entry.stat().st_size > 524288:
                    raise ValueError("plugin.py is missing, symlinked or oversized")
            except Exception as error:
                plugin.status = "invalid"
                plugin.error = str(error)[:1000]
            self.plugins[plugin.id] = plugin
        for plugin in self.plugins.values():
            self._load(plugin, set())
        self._publish()

    def _budget(self, callback, plugin, scene=0, args=(), seconds=0.05):
        begin = time.monotonic()
        deadline = begin + seconds
        token = _api._scope(plugin, scene)
        previous = sys.gettrace()

        def trace(frame, event, arg):
            if time.monotonic() > deadline:
                raise BudgetExceeded("Python callback exceeded its execution budget")
            return trace

        try:
            sys.settrace(trace)
            return callback(*args)
        finally:
            sys.settrace(previous)
            _api._context.reset(token)
            plugin.last_ms = round((time.monotonic() - begin) * 1000, 3)

    def _load(self, plugin, visiting):
        if plugin.status in {"invalid", "loaded", "error", "blocked"} or not plugin.enabled:
            return plugin.status == "loaded"
        if plugin.id in visiting:
            plugin.status, plugin.error = "blocked", "Dependency cycle"
            return False
        visiting = visiting | {plugin.id}
        for dep_id, minimum in plugin.dependencies.items():
            dep = self.plugins.get(dep_id)
            if not dep or dep.status == "invalid" or _version(dep.version) < minimum or not self._load(dep, visiting):
                plugin.status, plugin.error = "blocked", f"Dependency unavailable: {dep_id}"
                return False
        self._next(plugin)
        key = "_mucho_plugin_" + plugin.id.replace(".", "_").replace("-", "_") + "_" + str(plugin.generation)
        try:
            self.finder.roots[key] = plugin.path
            spec = importlib.util.spec_from_file_location(key, plugin.path / "plugin.py",
                loader=_SourceLoader(key, str(plugin.path / "plugin.py")), submodule_search_locations=[str(plugin.path)])
            module = importlib.util.module_from_spec(spec)
            sys.modules[key] = module
            plugin.module = module
            self._budget(lambda: spec.loader.exec_module(module), plugin, seconds=0.25)
            plugin.status, plugin.error, plugin.failures = "loaded", "", 0
            native.log(plugin.id, "Loaded " + plugin.version)
            return True
        except BaseException as error:
            plugin.status, plugin.error = "error", f"{type(error).__name__}: {error}"[:1000]
            self._unload(plugin)
            native.log(plugin.id, plugin.error)
            return False

    def _unload(self, plugin):
        if plugin.module:
            callback = getattr(plugin.module, "unload", None)
            if callable(callback):
                try:
                    self._budget(callback, plugin)
                except BaseException as error:
                    native.log(plugin.id, "Unload error: " + str(error))
            prefix = plugin.module.__name__
            self.finder.roots.pop(prefix, None)
            for key in list(sys.modules):
                if key == prefix or key.startswith(prefix + "."):
                    del sys.modules[key]
        self._next(plugin)
        plugin.module = None
        plugin.handlers.clear()
        plugin.buttons.clear()

    def _invoke(self, plugin, callback, scene, args=()):
        plugin.calls += 1
        try:
            self._budget(callback, plugin, scene, args)
            plugin.failures = 0
        except BaseException as error:
            plugin.failures += 1
            plugin.error = f"{type(error).__name__}: {error}"[:1000]
            native.log(plugin.id, plugin.error)
            if isinstance(error, BudgetExceeded) or plugin.failures >= 3:
                plugin.status = "error"
                self._unload(plugin)
                unavailable = {plugin.id}
                while True:
                    blocked = [p for p in self.plugins.values() if p.status == "loaded"
                               and unavailable.intersection(p.dependencies)]
                    if not blocked:
                        break
                    for dependent in blocked:
                        dependent.status = "blocked"
                        dependent.error = "Dependency failed: " + plugin.id
                        unavailable.add(dependent.id)
                        self._unload(dependent)

    def dispatch(self, name, payload, scene=0):
        data = json.loads(payload)
        if not isinstance(data, dict):
            raise ValueError("Event payload must be an object")
        if name == "ui.button":
            plugin = self.plugins.get(data.get("owner"))
            if plugin and plugin.status == "loaded" and type(data.get("generation")) is int and plugin.generation == data["generation"]:
                button = plugin.buttons.get(data.get("id"))
                if button:
                    self._invoke(plugin, button[1], scene)
        else:
            for plugin in self.plugins.values():
                if plugin.status != "loaded":
                    continue
                for callback in list(plugin.handlers.get(name, ())):
                    if plugin.status != "loaded":
                        break
                    self._invoke(plugin, callback, scene, (MappingProxyType(data),))
        self._publish()

    def control(self, raw):
        try:
            data = json.loads(raw)
            if not isinstance(data, dict):
                raise ValueError("Control must be an object")
            operation = data.get("operation")
            if operation == "rescan":
                self.scan()
                return
            plugin = self.plugins.get(data.get("id"))
            if not plugin or plugin.status == "invalid":
                raise ValueError("Plugin is missing or invalid")
            if operation not in {"enable", "disable", "reload"}:
                raise ValueError("Unknown control operation")
            if operation in {"enable", "disable"}:
                next_state = dict(self.state)
                next_state[plugin.id] = operation == "enable"
                temp = self.root / ".state.json.tmp"
                if temp.is_symlink():
                    raise ValueError("Saved state path is symlinked")
                temp.write_text(json.dumps(next_state, sort_keys=True), encoding="utf-8")
                os.replace(temp, self.root / ".state.json")
                self.state = next_state
            # Rebuild the dependency graph, including dependents, on every lifecycle change.
            # Relative helper modules are recreated; no .pyc cache is used by the runtime.
            self.scan()
        except Exception as error:
            native.log("runtime", "Control failed: " + str(error))
        self._publish()

    def _publish(self):
        native.publish(json.dumps([{
            "id": p.id, "name": p.name, "version": p.version, "enabled": p.enabled,
            "status": p.status, "error": p.error, "generation": p.generation,
            "calls": p.calls, "last_ms": p.last_ms,
            "dependencies": {key: ".".join(map(str, version)) for key, version in p.dependencies.items()},
            "buttons": [{"id": key, "title": title} for key, (title, _) in p.buttons.items()],
        } for p in self.plugins.values()], ensure_ascii=True))

    def shutdown(self):
        for plugin in self.plugins.values():
            self._unload(plugin)
        if self.finder in sys.meta_path:
            sys.meta_path.remove(self.finder)
