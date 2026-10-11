# Compatibility contract

Base: Geode v5.11.1, commit `413ac9b3936373e0983b3ebb8a056a3d39e8541c`, GD 2.2081.

| Surface | Contract |
|---|---|
| Native `.geode` archives | Upstream parser, loader and dependency resolution retained |
| Public SDK headers | Byte-for-byte unchanged from pinned upstream |
| Internal loader ID | `geode.loader` retained |
| Native library filenames | `Geode.dll`, `Geode.android64.so`, `Geode.android32.so` retained |
| ABI version | Upstream `VERSION` retained; Mucho version is independent |
| Native mod paths/settings | Existing upstream directory layout retained |
| DLL exports | Release gate checks no named upstream export is missing |
| Python packages | Separate Mucho API 1; not interchangeable with native mods |
| Safe/forward compatibility modes | Do not start the Python interpreter |
| Loader updates | Upstream replacement blocked; native mod updates retained |

These source and export checks do not establish runtime semantics, exact
memory layout of all dependencies, hook ordering for every combination of
mods, Android launcher interoperability, or support for different GD versions.
Unknown native mods and game/device combinations remain unverified.
The release report deliberately does not mark all mods compatible.

Device checklist: native mod discovery, Node IDs/layout coexistence, repeated
level restarts, dual-player deaths, practice/normal completion, scene transitions,
enable/disable during queued events, popup lifecycle and installer rollback.

Changes to exported headers, core loader identity or removed baseline exports
must fail the compatibility gate before distributing a package.
