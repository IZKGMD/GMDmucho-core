# Changelog

## v1.0.0 — Release Candidate

MuchoCore v1.0.0 packages the current server core, deployment tooling, client patching tools and compatibility checks into a single release candidate.

### Included

- Geometry Dash account, profile, level, social and moderation endpoints;
- version-aware Geometry Dash protocol handling with explicit 2.2 logic;
- GJP2-aware authentication for modern clients;
- cloud save;
- Secret Room / Wraith reward handling;
- player dashboard and administration panel;
- music upload infrastructure;
- Docker Compose deployment with MariaDB and Caddy;
- automated database migrations;
- automated PHP, shell, Python, Docker and Caddy validation;
- Windows client patching tools;
- built-in client tracing and version-aware contract generation.

### Compatibility gate

The final 2.2 compatibility claim is intentionally blocked until a real Geometry Dash 2.2 client trace has been captured and committed as:

~~~text
tests/client-fixtures/2.2/endpoints.json
~~~

A synthetic or hand-written fixture does not satisfy the release gate.
