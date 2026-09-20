# Geometry Dash Client Compatibility

MuchoCore keeps version differences at the compatibility boundary: the shared
backend stays the same while request authentication, legacy parameter names,
and protocol details are adapted for the client family.

## Compatibility targets

| Geometry Dash family | gameVersion | Credential preference | Server-side status |
| --- | ---: | --- | --- |
| 1.0–1.8 | 1–18 | `gjp` | Compatibility target |
| 1.9 | 19 | `gjp` | Compatibility target |
| 2.0 | 20 | `gjp` | Compatibility target |
| 2.1 | 21 | `gjp` | Compatibility target |
| 2.2 | 22 | `gjp2` | Compatibility target |

The exact `binaryVersion` can vary between updates. MuchoCore uses it for
identification/tracing and keeps the main feature implementation shared.

## Endpoint compatibility

The router accepts both modern and legacy Geometry Dash endpoint names,
including versioned paths such as:

- `getGJLevels19/20/21`
- `downloadGJLevel19/20/21/22`
- `updateGJUserScore19/20/21/22`
- `getGJComments19/20/21`
- `likeGJItem19/20/21/211`
- `likeGJLevel`
- `updateGJDesc20`

It also strips common `/database`, `/accounts`, `/api`, and legacy
`/a` prefixes, so old GDPS URL layouts can point at the same core.

## Authentication compatibility

Requests from 2.1 and older prefer `gjp`. Requests from 2.2 prefer
`gjp2`. When a client omits `gameVersion`, MuchoCore can infer the family
from a versioned endpoint name such as `getGJLevels21.php`,
`downloadGJLevel22.php`, `getGJLevelScores211.php`, or the 2.2
platformer-score endpoint.

Legacy level uploads also accept the older `unlisted`, `unlisted1`, and
`unlisted2` parameter names.

## Current verification level

The compatibility layer is covered by server-side unit/contract tests for
routing, client-family detection, and authentication selection. The real
Geometry Dash client itself has not yet been used as the final compatibility
oracle.

See `CLIENT_TESTING.md` to capture requests from real clients and build a
compatibility contract from actual traffic.
