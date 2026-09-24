# Geometry Dash Protocol Compatibility Matrix

This document is the compatibility roadmap for MuchoCore's native Geometry Dash protocol layer.

The target is not a collection of version-specific PHP entrypoints. MuchoCore keeps one domain implementation and layers protocol compatibility on top of it.

## Status legend

- **Shared** — handled by the current domain service and reachable through compatibility routing.
- **Alias** — the legacy/versioned endpoint is normalized to the current handler.
- **Verified** — covered by a real client fixture and regression test.
- **Pending** — the route exists, but exact request/response parity still needs a real client fixture.
- **Missing** — not implemented yet.

## Current matrix

| Protocol area | GD 1.9 | GD 2.0 | GD 2.1 | GD 2.2 | Current MuchoCore state |
| --- | --- | --- | --- | --- | --- |
| Account registration | Alias | Alias | Alias | Alias | Pending |
| Account login | Alias | Alias | Alias | Alias | Pending |
| Cloud save backup | Alias | Alias | Alias | Alias | Pending |
| Cloud save sync | Alias | Alias | Alias | Alias | Pending |
| Level search/list | Alias | Alias | Shared | Alias | Pending |
| Level upload | Alias | Alias | Shared | Shared | Pending |
| Level download | Alias | Alias | Shared | Shared | Pending |
| Level delete | Alias | Shared | Shared | Shared | Pending |
| Level description update | Alias | Shared | Shared | Shared | Pending |
| User profile | Alias | Shared | Alias | Alias | Pending |
| User search | Alias | Shared | Alias | Alias | Pending |
| User scores | Alias | Shared | Alias | Alias | Pending |
| User settings | Alias | Shared | Alias | Alias | Pending |
| User score update | Alias | Shared | Alias | Shared | Pending |
| Level comments | Alias | Shared | Shared | Alias | Pending |
| Account comments | Alias | Shared | Alias | Alias | Pending |
| Likes | Alias | Alias | Shared | Shared | Pending |
| Messages | Alias | Shared | Alias | Alias | Pending |
| Friend requests / friends | Alias | Shared | Alias | Alias | Pending |
| Blocking | Alias | Shared | Alias | Alias | Pending |
| Creator discovery | Shared | Shared | Shared | Shared | Pending |
| Daily level | Shared | Shared | Shared | Shared | Pending |
| Gauntlets | Alias | Alias | Shared | Shared | Pending |
| Map packs | Alias | Alias | Shared | Shared | Pending |
| Level scores | Alias | Alias | Shared | Shared | Pending |
| Platformer scores | Pending | Pending | Pending | Shared | Pending |
| Rewards | Shared | Shared | Shared | Shared | Pending |
| Challenges | Shared | Shared | Shared | Shared | Pending |
| Level lists | Shared | Shared | Shared | Shared | Pending |
| Custom content/account URLs | Shared | Shared | Shared | Shared | Pending |

## Version policy

### 1.9

The first legacy target.

Priority:

1. credential handling;
2. account endpoints;
3. level discovery;
4. level upload/download;
5. user profile and score endpoints;
6. comments and social;
7. cloud save;
8. real-client fixtures.

### 2.0

After 1.9 request/response parity is stable, verify the 2.0-specific fields and endpoint aliases rather than duplicating the domain implementation.

### 2.1

2.1 is the first generation where URL formats and several protocol conventions diverge from older clients. Every 2.1 fixture must therefore validate both routing and encoded request/response fields.

### 2.2

2.2 remains the modern baseline. Version-specific behavior should stay explicit instead of being silently treated as generic 2.x traffic.

## Real-client contract

A compatibility claim becomes **Verified** only after a real client request/response fixture exists.

Fixtures live under:

```text
tests/client-fixtures/
├── 1.9/
├── 2.0/
├── 2.1/
└── 2.2/
```

The contract should capture:

- HTTP method;
- exact endpoint path;
- relevant request fields;
- expected status;
- expected response shape;
- version-specific quirks.

## Cvolton baseline

Cvolton's GMDprivateServer currently documents support for Geometry Dash 1.0 through 2.2. Its repository uses versioned endpoint wrappers around shared implementations, and its long-lived compatibility surface is the baseline MuchoCore is trying to exceed while keeping a cleaner architecture.

Reference:

- https://github.com/Cvolton/GMDprivateServer
- https://github.com/Cvolton/GMDprivateServer/wiki/Deliberate-differences-from-real-GD

## GD 2.1 implementation status

**100% complete for the current server-side protocol scope.** The implementation covers the 2.1 wire-level text encoding rules, level hashes, profile-state normalization, compatibility routing, authenticated friends-level access, and the 2.1 level-score response contract.

## Next milestone

The implementation is complete. The remaining operational milestone is empirical verification: capture a real Geometry Dash 2.1 trace and pass `tests/release/release-2.1-gate.sh`.

Until the real-client fixture exists, endpoint rows remain `Pending` by design and are not presented as empirically verified.

The 2.2 release gate remains regression-locked. Do not mark an endpoint `Verified` because its alias exists; mark it verified only when the real client contract passes.
