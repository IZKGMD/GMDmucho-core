# MuchoLoader 0.1.0

Standalone Geometry Dash loader based on Geode **5.11.1**, for GD **2.2081**.
It is a separate source project and native loader binary, not a `.geode` mod.
Geode's attribution, SDK, native mod format, IDs, library names and public headers
are retained. MuchoLoader is not an official Geode release.

## What is implemented

- Existing native `.geode` loading continues through the upstream loader.
- Built-in Python runtime on Windows x64, using the CPython C API.
- Python SDK with `events`, `ui.button`, `ui.notice`, `effects.pulse`, and `log`.
- In-game **MUCHO** hub: plugin status, dependencies, enable/disable, reload,
  rescan, paged plugin buttons, errors and recent logs.
- Separate Python worker; bounded event and command queues. Only native game
  commands run on the game thread. No game pointers are handed to Python.
- Generations invalidate pending commands and buttons after reload/disable.
- Python exceptions are logged; repeated failures disable that plugin's runtime.
  A cooperative trace budget catches ordinary Python loops.
- Installation hashes, backup and restore. Existing native mods/configs remain
  under the upstream directory layout.

## Python plugin

Place a folder such as `hello-python` in `geode/mucholoader/plugins`:

```json
{"id":"hello-python","name":"Hello Python","version":"1.0.0","api":1,"enabled":true}
```

Its `plugin.py` can contain:

```python
from mucho import events, ui, effects

ui.button("hello", "Hello", lambda: ui.notice("Python called native C++!"))

@events.on("level.start")
def on_start(event):
    effects.pulse("#A855F7", duration=0.25, opacity=50)
```

Events currently implemented: `menu.ready`, `level.start`, `level.death`,
`level.complete`. An event is an immutable mapping. `level.start` includes
`level_id`. Buttons are shown on the hub's **Buttons** page.
Python types for editor completion are included in `sdk/mucho/__init__.pyi`.

## Manifest and lifecycle

`id` must equal the folder name and use lowercase letters, digits, `.`, `_`, `-`.
`api` is exactly `1`; versions use `MAJOR.MINOR.PATCH`. New plugins default to
disabled. Optional `min_loader_version` / `max_loader_version` constrain the
Mucho Python layer. `dependencies` maps Python plugin IDs to minimum versions.
Missing/disabled/incompatible dependencies block a dependent plugin.
If a running dependency is quarantined after errors, its transitive dependents
are unloaded and marked blocked too. Reload reconstructs the graph.

Enable/disable persists in `.state.json`. Reload and rescan rebuild the complete
dependency graph; relative helper modules are unloaded too. A module may define
`unload()` for cooperative cleanup. Plugins must not leave unmanaged threads,
timers or retained external native objects behind. The SDK is worker-thread owned.

## Build

Build from the repository root using upstream Geode's platform toolchain:

```sh
cmake -S . -B build -G Ninja -DCMAKE_BUILD_TYPE=RelWithDebInfo \
  -DGEODE_BINDINGS_REPO_PATH=/absolute/path/to/pinned-bindings \
  -DGEODE_DONT_BUILD_TEST_MODS=ON -DMUCHO_WITH_PYTHON=ON
cmake --build build --parallel 2
```

Python development headers and libraries **for the target architecture** are
required for embedding. CI pins Geode and bindings and builds the standalone
Windows library, native Android cores, and independent runtime tests.
Use the bindings checkout at `f8aec5d1f3df76d840dfd2609f02cef30dc6efdf`;
the complete platform/toolchain settings are in `ci.yml`. The source archive
also includes a copy of this pinned bindings source under `vendor/bindings`.
Native DLL / SO filenames retain Geode names for binary compatibility.
The package/display name is MuchoLoader; `VERSION` stays the upstream ABI version.

Independent SDK tests need no GD installation:

```sh
cmake -S mucho -B build-mucho-tests
cmake --build build-mucho-tests
ctest --test-dir build-mucho-tests --output-on-failure
```

## Compatibility and limits

`tools/check_compatibility.py` verifies the unchanged upstream source contracts
and, on Windows, that no named baseline DLL export has disappeared. Export
coverage is a compatibility gate, not proof that every mod works at runtime.
See `docs/COMPATIBILITY.md` and the generated `compatibility.json`.

This first version does not claim superiority over Geode as a whole or 100%
compatibility with every mod. Real GD/game-device testing remains necessary.
Python embedding is enabled in the Windows package. Android cores preserve
native loading but currently report Python as unavailable; Android interpreter
packaging and an independent Android launcher are future work.

The interpreter is **not a security sandbox**. Python plugins are executable
local code and have normal CPython/OS privileges. Only install trusted plugins.
The trace budget cannot interrupt blocking native calls such as `time.sleep`,
and is not an adversarial CPU/memory limit. Arbitrary native hooks, trigger
group events and a remote Python marketplace are not yet exposed.
Updates from the upstream loader updater are blocked to prevent it overwriting
this fork. Native mod catalog/downloads and resource validation remain upstream.

## Next milestones

1. Verify installation, UI, event timing and coexistence with representative
   native mods on real Windows GD 2.2081.
2. Android CPython packaging and launcher, then the same device tests.
3. More typed events/actions, Python development console and profiling.
4. Verified plugin distribution and a maintained compatibility matrix.
