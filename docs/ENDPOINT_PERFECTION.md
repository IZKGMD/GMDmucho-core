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

## CI gate

`tests/protocol/endpoint-perfection.php` verifies that:

- contract IDs and aliases are unique;
- every alias resolves to the declared canonical route;
- every required quality dimension is present;
- every `covered` claim links to repository evidence;
- performance budgets are explicit;
- every committed real-client fixture endpoint is represented in the perfection registry;
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

Next cohorts should add score submission, profile/update-score flows, comments writes, likes, social, cloud save, rewards, songs, map packs, gauntlets and level lists until every supported game endpoint is represented.

## Certification rule for 1.1

The target state for the stable 1.1 release is:

> Every supported Geometry Dash endpoint is registered, version-aware and backed by protocol evidence. Release-gated endpoints have no pending quality dimension.

The registry is intentionally stricter than the existing protocol-area matrix. The matrix says whether a feature exists; Endpoint Perfection says whether its behavior is proven.
