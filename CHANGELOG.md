# Changelog

## v1.0.1 — подготовка

This candidate packages the current MuchoCore server, deployment helpers, client patching tools and compatibility tests into a release-ready tree.

### Included

- beginner-first VPS and shared-hosting documentation;
- one-command Docker deployment with generated secrets;
- Windows client patchers for supported Geometry Dash URL formats;
- legacy Geometry Dash routing compatibility;
- shared-hosting Apache routing regression tests;
- Cloudflare Tunnel deployment mode for NAT/CGNAT VPS environments;
- production Composer dependencies in the generated release archive;
- CI validation for shell, PHP, Python, Caddy, routing and Windows PowerShell tooling.

### Compatibility note

Automated checks validate the server-side contract and patcher behavior. A real Geometry Dash client build must still be tested separately before claiming compatibility with that exact client version.
