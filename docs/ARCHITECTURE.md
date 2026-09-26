# MuchoCore Architecture

MuchoCore keeps the Geometry Dash protocol surface compatible while keeping the core runtime small and modular.

## Runtime flow

```text
public/index.php
      |
      v
Application
      |
      +--> AppServices
      |      |
      |      +--> repositories
      |      +--> services
      |      +--> controllers
      |      +--> plugins
      |
      +--> AppRoutes
      |
      v
RequestPipeline
      |
      +--> MuchoProtect
      +--> CompatibilityProfile
      +--> Router
      |
      v
Controller -> Service -> Repository -> Database
```

## Responsibilities

### Application

`Application` only wires the kernel together. It does not contain protocol routes, feature construction or request business logic.

### AppServices

`AppServices` is the single dependency wiring point. Shared dependencies such as PDO, authentication and legacy identity resolution are constructed once and reused by the feature modules.

### AppRoutes

`AppRoutes` is the single route map. Compatibility endpoints remain explicit, but route registration is data-driven where several paths use the same controller method.

### RequestPipeline

`RequestPipeline` owns the request lifecycle:

- request tracing;
- MuchoProtect pre-check;
- compatibility profile validation;
- router dispatch;
- plugin completion/failure events;
- protocol-safe `-1` fallback.

### Router

The router only handles path normalization and dispatch. Legacy URL aliases live in `CompatibilityAliases`, not inside the router implementation.

### LegacyEndpoint

Simple legacy controllers use `LegacyEndpoint::text()` for the common try/catch and `-1` fallback. Controllers that need detailed diagnostics can keep custom handling.

## Feature modules

Feature code remains in small vertical slices:

```text
Account
CloudSave
Clan
Comment
Compatibility
Interaction
Level
Moderation
Music
Score
Social
User
```

A feature should prefer:

```text
Controller
   -> Service
      -> Repository
```

Protocol-specific encoders and compatibility handling stay at the boundary instead of spreading through unrelated modules.

## Rules for new code

1. Do not add route registration to `Application.php`.
2. Do not add a large dependency graph to `public/index.php`.
3. Keep raw PDO access inside repositories or explicitly database-oriented compatibility modules.
4. Reuse `Request` for request input instead of reading `$_POST` directly.
5. Reuse `LegacyEndpoint` for ordinary legacy text responses.
6. Keep Geometry Dash compatibility behavior explicit and tested.
7. Prefer one source of truth for permissions, validation and lifecycle rules.
8. Add a contract test for a new cross-module behavior.

The goal is not to remove necessary Geometry Dash compatibility code. The goal is to isolate it so the rest of MuchoCore stays straightforward to maintain.
