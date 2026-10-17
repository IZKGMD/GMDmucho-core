# Geometry Dash 2.209 — native endpoint coverage

The 2.209 update introduced 52 new endpoint names, including 22 new moderation
routes, per the [GD Docs 2.209 changelog](https://github.com/gd-docs/gd-docs/blob/main/docs/changelog.md).
MuchoCore tracks their routing separately from exact wire protocol parity.

Run the route inventory:

```sh
php bin/mucho-gd2209-coverage.php
php bin/mucho-gd2209-coverage.php --json
```

`--fail-missing` exits nonzero until **every** new name is registered.
It is intended as a release-gate command, not as a passing CI check while
routes remain unimplemented.

A route being registered is *not enough* to call it compatible: native GD 2.209
needs the correct POST parameters, credential validation, response framing,
empty and error semantics, persistence, and live client acceptance.

## Moderation Queue

New moderator-specific routes include `adminGetReportQueue`,
`adminGetReportedContent`, `adminGetUserHistory`, and several comment,
ban, investigation and case actions. Their request and response semantics are
**not** yet documented publicly to a degree that permits exact implementation.
Do not return an invented list or a success marker to conceal missing support.

Separately, existing `getGJLevels21` uses `type=8` for pending moderator
suggestions and `type=27` for a signed-in advisor's sent suggestions.
MuchoCore now implements those two filters and authenticates the requester.

To identify the actual failing call, enable `MUCHO_CLIENT_TRACE=1` for the
app service, reproduce Moderation Queue once in a real 2.209 client, and inspect:

```sh
php bin/mucho-gd2209-trace-report.php --min-binary=51 --json
```

A **local copy** of the trace should be treated as private diagnostic data.
The trace captures request field names and safe numeric list selectors but
not credentials, arbitrary field values or response bodies.

## 100% native compatibility gate

1. Register all 52 documented 2.209 endpoints plus any additional discovered
   routes, without granting moderator permissions to non-moderators.
2. Implement exact byte-level request/response fixtures captured on
   legally obtained Windows and Android 2.209 clients.
3. Confirm 2.209-specific keys (`62`, `63`, `54`), thread fields, notification
   fields, editor audio, death data and new error payloads.
4. Check new administrative commands with a test matrix of regular users,
   advisors, moderators and owners, including access denial and repeat calls.
5. Rebuild native MuchoClient on supported Geode bindings for 2.209.
6. Run migration-integrity, PHP unit/integration and real-client end-to-end
   tests with no regressions or performance blockers.

Do not publish a "100%" success badge solely from a successful CI pipeline.
