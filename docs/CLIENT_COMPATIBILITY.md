# Geometry Dash Client Compatibility

MuchoCore follows the same version-awareness principle used by established GDPS server implementations: the client declares its `gameVersion` and `binaryVersion`, and the server keeps version differences at the compatibility boundary instead of duplicating the whole backend.

## Supported version families

| Geometry Dash family | gameVersion | Example binaryVersion | Credential preference |
| --- | ---: | ---: | --- |
| 1.0 | 1 | legacy / versionless | legacy GJP |
| 1.1 | 11 | legacy / versionless | legacy GJP |
| 1.5 | 15 | legacy / versioned aliases | legacy GJP |
| 1.9 | 19 | 25 | `gjp` |
| 2.0 | 20 | 27-29 | `gjp` |
| 2.1 | 21 | 33-35 | `gjp` with `gjp2` fallback |
| 2.2 | 22 | 37-42 | `gjp2` |

The exact binary version can vary between game updates. MuchoCore uses it for identification and tracing while routing the endpoint to the shared service implementation.

## GD 1.0 / 1.1 legacy boundary

GD 1.0 and GD 1.1 are treated as separate protocol generations because their `gameVersion` values identify different client families, while the early API surface remains on the unsuffixed legacy endpoint family.

For GD 1.1, MuchoCore accepts `gameVersion=11`, keeps legacy GJP authentication, and routes the shared legacy endpoints through the same compatibility layer used by older clients. No GJP2 processing is enabled for this family.

The server does not require a dedicated 1.1 backend copy. Version-specific behavior belongs in the compatibility boundary so that the same account, level, social, and moderation services can be reused safely.

## Endpoint compatibility

Versioned Geometry Dash endpoint names are normalized in the router. Older names such as `loginGJAccount20`, `getGJLevels20`, `downloadGJLevel20`, and `getGJLevelScores20` are mapped to the shared MuchoCore handlers.

Unsuffixed legacy endpoints remain available for the early client families, while explicit `gameVersion=11` requests are identified as GD 1.1 before dispatch.

This lets multiple client generations use the same database and service layer.

## Authentication compatibility

Geometry Dash 2.1 and older requests prefer `gjp`. Geometry Dash 2.2 prefers `gjp2`. When both values are present, MuchoCore selects the credential according to the detected client family and keeps the other value as a fallback.

GD 1.1 therefore uses the legacy credential path and does not require GJP2.

## 1.9 status

**Implementation completeness: 100%.** The 1.9 compatibility pass covers legacy GJP selection, versioned endpoint aliases, level description/comment wire rules, legacy level-download password framing, level hashes, old-binary comment user sections, user-comment history, profile/leaderboard routes, and legacy level-browser filters.

A separate empirical verification gate requires a real Geometry Dash 1.9 trace fixture.

## 1.5 status

**Implementation completeness: compatibility layer complete.** GD 1.5 is admitted as `gameVersion=15`, uses legacy GJP authentication, and its comment endpoints are normalized to the shared comment handlers.

A separate empirical verification gate requires the real GD 1.5 client test described in `CLIENT_TESTING.md`.

## 1.1 status

**Implementation completeness: compatibility layer complete.** GD 1.1 identification, profile admission, legacy credential selection, and regression coverage are implemented. The shared early-client endpoint surface is reused rather than duplicated.

A separate empirical verification gate still requires a real Geometry Dash 1.1 trace fixture from the target client build. This records runtime verification separately from server-side implementation coverage.

## Cross-version validation status

The automated protocol suite for GD 1.0, 1.1 and 1.9–2.2 covers version identification, routing, credential selection, text encoding, level hashes, profile fields, leaderboard authentication, level comments, social wire fields, and regression guards.

Empirical status remains separate: GD 2.2 has a captured real-client fixture; GD 1.0, 1.1, 1.9, 2.0 and 2.1 do not have dedicated real-client fixtures in the repository.

## 2.0 status

**Implementation completeness: 100%.** The GD 2.0 server-side protocol surface is implemented, including compatibility routing, legacy GJP authentication, level transfer, comments, profile/leaderboard responses, social endpoints, cloud-save aliases, and level-score framing.

A separate empirical verification gate requires a real Geometry Dash 2.0 trace fixture. This records runtime verification separately from implementation completeness.

## 2.1 status

**Implementation completeness: 100%.** The 2.1 server-side protocol implementation is complete for the current scope, including routing, level/comment wire encoding, profile state normalization, level hashes, restricted downloads, and level-score response framing.

A separate empirical verification gate still requires a real Geometry Dash 2.1 trace fixture. This does not reduce the implementation completeness figure; it records runtime verification status separately.

## What this does not claim

Server-side compatibility tests do not prove that every real Geometry Dash client works. Real clients must still be tested.

Use `CLIENT_TESTING.md` to capture requests from real clients and generate a compatibility contract from actual traffic.
