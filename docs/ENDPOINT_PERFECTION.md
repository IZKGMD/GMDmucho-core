# Endpoint Perfection — MuchoCore 1.1

MuchoCore 1.1 treats Geometry Dash endpoint compatibility as a release-quality contract, not as a best-effort routing layer.

## Definition of a perfect endpoint

An endpoint is release-perfect only when all applicable dimensions are green:

1. **Request contract** — accepted fields, defaults, version-specific aliases and malformed-input behavior are defined.
2. **Wire contract** — exact response framing, key order where protocol-significant, delimiters, hashes, XOR/Base64 behavior and failure sentinel are covered.
3. **Authentication contract** — required credential type, legacy fallback boundaries and account isolation are explicit.
4. **Negative cases** — bad credentials, missing objects, invalid pages, malformed payloads and duplicate operations have regression coverage.
5. **Database integrity** — write endpoints prove the resulting database state, not only the returned text.
6. **Concurrency** — write endpoints that can race are exercised under duplicate/parallel requests.
7. **Performance budget** — the endpoint has a p95 target on the reference dataset.
8. **Real-client evidence** — at least one captured client request is linked for each supported family before final certification.

The machine-readable registry lives in `tests/protocol/endpoint-contracts.json`.

## Complete route inventory (release work)

The registry now inventories **87 canonical application game routes**, derived
from 91 statically registered paths in `src/Core/Application.php` (excluding
`/health` and collapsing routes that normalize to the same canonical path).
The original six P0 contracts retain their existing evidence statuses. The
other 81 routes are explicitly `inventory_only: true` and **not certified**.

Inventory-only entries have `priority: "untriaged"`, `method: "ANY"`
(the actual router registration type), `families: ["unverified"]`, and
`pending` quality dimensions. No real-client coverage or p95 performance is
claimed. A null `p95_ms` means no performance budget has yet been agreed.
Do not convert these entries to `covered` or `release_gate: true` until
version-specific request/response, authorization, negative, DB, concurrency,
performance and real-client evidence actually exists.

The CI gate compares **every static core game route** with this inventory in
both directions. New routes cannot silently bypass certification tracking;
unregistered/stale contracts also fail validation. Dynamic third-party plugin
routes are deliberately outside this application-route inventory.

Before certifying one entry, replace `ANY` with the verified HTTP method,
replace `unverified` with observed client versions, set a defensible p95
budget, attach real executable evidence, and clear `inventory_only`.

## CI gate

`tests/protocol/endpoint-perfection.php` verifies that:

- contract IDs and aliases are unique;
- every alias resolves to the declared canonical route;
- every required quality dimension is present;
- every `covered` claim links to repository evidence;
- performance budgets must be explicit before a contract is certified;
- every committed real-client fixture endpoint is represented in the perfection registry;
- every statically registered core game route appears in the registry exactly once;
- an endpoint cannot set `release_gate: true` while any required dimension is still `partial` or `pending`.

This means new real-client traces cannot silently expand the protocol surface without also expanding the endpoint quality registry.

## Rollout

The first P0 cohort is:

- account login;
- level search;
- level upload;
- level download;
- leaderboard lookup;
- level comment reads.

The remaining inventory now includes score submission, profile/update-score flows, comment writes, likes, social, cloud save, rewards, songs, map packs, gauntlets, and level lists. Each must move from unverified inventory to independently tested certification before the stable release.

## Certification rule for 1.1

The target state for the stable 1.1 release is:

> Every supported Geometry Dash endpoint is registered, version-aware and backed by protocol evidence. Release-gated endpoints have no pending quality dimension.

The registry is intentionally stricter than the existing protocol-area matrix. The matrix says whether a feature exists; Endpoint Perfection says whether its behavior is proven.
