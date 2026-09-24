# Geometry Dash Client Compatibility

MuchoCore follows the same version-awareness principle used by established GDPS server implementations: the client declares its `gameVersion` and `binaryVersion`, and the server keeps version differences at the compatibility boundary instead of duplicating the whole backend.

## Supported version families

| Geometry Dash family | gameVersion | Example binaryVersion | Credential preference |
| --- | ---: | ---: | --- |
| 1.9 | 19 | 25 | `gjp` |
| 2.0 | 20 | 29 | `gjp` |
| 2.1 | 21 | 33-35 | `gjp` with `gjp2` fallback |
| 2.2 | 22 | 37-42 | `gjp2` |

The exact binary version can vary between game updates. MuchoCore uses it for identification and tracing while routing the endpoint to the shared service implementation.

## Endpoint compatibility

Versioned Geometry Dash endpoint names are normalized in the router. Older names such as `loginGJAccount20`, `getGJLevels20`, `downloadGJLevel20`, and `getGJLevelScores20` are mapped to the shared MuchoCore handlers.

This lets multiple client generations use the same database and service layer.

## Authentication compatibility

Geometry Dash 2.1 and older requests prefer `gjp`. Geometry Dash 2.2 prefers `gjp2`. When both values are present, MuchoCore selects the credential according to the detected client family and keeps the other value as a fallback.

## 1.9 status

**Implementation completeness: 100%.** The 1.9 compatibility pass covers legacy GJP selection, versioned endpoint aliases, level description/comment wire rules, legacy level-download password framing, level hashes, old-binary comment user sections, user-comment history, profile/leaderboard routes, and legacy level-browser filters.

A separate empirical verification gate requires a real Geometry Dash 1.9 trace fixture.

## Cross-version validation status

The automated protocol suite for GD 1.9–2.2 passes on the current `main` revision, including routing, text encoding, level hashes, profile fields, leaderboard authentication, level comments, social wire fields, and regression guards.

Empirical status remains separate: GD 2.2 has a captured real-client fixture; GD 1.9, 2.0 and 2.1 do not have real-client fixtures because runtime client capture was intentionally skipped.

## 2.0 status

**Implementation completeness: 100%.** The GD 2.0 server-side protocol surface is implemented, including compatibility routing, legacy GJP authentication, level transfer, comments, profile/leaderboard responses, social endpoints, cloud-save aliases, and level-score framing.

A separate empirical verification gate requires a real Geometry Dash 2.0 trace fixture. This records runtime verification separately from implementation completeness.

## 2.1 status

**Implementation completeness: 100%.** The 2.1 server-side protocol implementation is complete for the current scope, including routing, level/comment wire encoding, profile state normalization, level hashes, restricted downloads, and level-score response framing.

A separate empirical verification gate still requires a real Geometry Dash 2.1 trace fixture. This does not reduce the implementation completeness figure; it records runtime verification status separately.

## What this does not claim

Server-side compatibility tests do not prove that every real Geometry Dash client works. Real clients must still be tested.

Use `CLIENT_TESTING.md` to capture requests from real clients and generate a compatibility contract from actual traffic.
