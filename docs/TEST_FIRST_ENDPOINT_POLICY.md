# MuchoCore: verify endpoints during development (post-v1.1.1)

MuchoCore v1.1.1 is a PHP 8 stable maintenance release, **not** complete
Geometry Dash 2.209 native-client certification.

## Starting point

- Endpoint registry: 166 tracked application routes.
- 160 routes are still inventory-only, without verified native behavior.
- Six P0 contracts have incomplete quality dimensions, and no current
  registry entry is release-gated. A green CI is not 166/166 certification.
- uploadMPComment.php has no verified real-client contract yet; never fabricate
  a successful response.

## Mandatory gates for each pull request

1. PHP 8 HTTP acceptance now runs on **every PR**, including changes to
   protocol fixtures, tests, deployment tooling and documentation.
2. The suite uses isolated PHP 8.3, PHP-FPM, MariaDB and Caddy. It sends
   anonymous and malformed requests to all inventoried routes and replays all
   113 direct GD 2.209 Windows EXE request paths using synthetic inputs.
3. The HTTP regression creates disposable test accounts and calls
   gd2209-exe-authenticated-http.py, verifying selected authenticated native
   protocol envelopes and actual database changes.
4. The CI continuity contract prevents silent removal of these acceptance
   jobs and accidental reintroduction of pull-request path filtering.
5. The endpoint inventory growth gate freezes v1.1.1's historical technical
   debt: no newly added route may be inventory-only. New routes require
   complete request, wire, authentication, negative-case, persistence,
   concurrency where applicable, performance and native-client evidence.

## Feature-by-feature development rules

Keep production code and its supporting tests in **the same PR**.

- Define exact request fields, aliases, client versions and error behavior.
- Assert actual native response bytes, encoding and empty-result sentinels.
- For write operations verify account ownership, invalid credentials,
  duplicate calls, permissions and persisted SQL rows on an isolated DB.
- For concurrency-sensitive operations add concurrent request tests.
- For performance-sensitive paths measure p95 before claiming improvement.
- Record redacted native Windows/Android 2.209 fixtures for empirical
  certification. Synthetic static EXE replay does NOT certify a real client.
- Update registry quality statuses only when supported by concrete evidence.

Never run destructive regressions on a player database. Never commit raw
credentials, GJP2 tokens or full private traffic.

## Release policy

Only merge a feature with both Validate and PHP 8 HTTP acceptance green for
the PR's exact code. Keep v1.1.1 and main unchanged until a PR is reviewed.
A new stable release requires its own exact-commit CI plus real-client
evidence for every compatibility claim. Green routing alone is insufficient.

The existing 160 inventory-only routes are acknowledged technical debt:
their count should decrease with individually verified fixes. The baseline
must not be used as proof that they work.
