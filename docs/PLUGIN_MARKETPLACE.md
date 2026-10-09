# MuchoCore Plugin Marketplace — Discovery Preview

MuchoCore 1.1 introduces a developer-friendly discovery surface in
**Admin → Custom Plugins**. It is not a remote PHP package installer.

## What ships now

- A bundled, versioned JSON catalog at resources/plugin-catalog.json.
- A strictly validated, read-only catalog parser.
- Display of plugin name, version, required MuchoCore release, SDK API,
  declared SDK permissions, and reviewed source-code link.
- A working sample at examples/plugins/welcome-endpoint/.
- Compatibility indicators without evaluating or executing plugin code.
- Contract tests for malformed entries, duplicate IDs, unsafe links,
  unknown SDK permissions, and older core versions.

The first-party sample adds a GET /extensions/welcome route. For
manual installation, review and copy its manifest.json and plugin.php into
custom/plugins/welcome-endpoint/ and restart the application.
Keep this directory untracked so MuchoCore updates preserve it.

## Plugin SDK compatibility

The current SDK generation is **API 1**, with explicitly declared capabilities:
events, routes, and database. SDK permissions are **not a PHP sandbox**:
plugins run inside the server process. Only install trusted, audited source.

Catalog entries use this format:

~~~json
{
  "schema_version": 1,
  "plugins": [
    {
      "id": "welcome-endpoint",
      "name": "Welcome Endpoint",
      "description": "Example extension.",
      "version": "1.0.0",
      "api": 1,
      "min_core_version": "1.1.0",
      "permissions": ["routes"],
      "source_url": "https://github.com/IZKGMD/GMDmucho-core/tree/main/examples/plugins/welcome-endpoint"
    }
  ]
}
~~~

An entry may also declare an optional max_core_version in MAJOR.MINOR.PATCH
format. Its bound is checked alongside min_core_version and the SDK API.

Catalog entries require a source link on GitHub over HTTPS. A new entry is
published by a reviewed repository change. The catalog has no remote feed,
update endpoint, upload control, or automatic installation.

## Planned for a future release

Before enabling a one-click store, implement all of:

1. Signed package and publisher verification, with checksums, a review/approval
   process, and no execution based on catalog text alone.
2. Dependency resolution and SDK/core compatibility enforcement.
3. Admin confirmation of requested capabilities and an auditable install plan.
4. Safe archive extraction, limits, path-traversal/symlink prevention,
   atomic installation, rollback and upgrade support.
5. Explicit enable/disable, plugin configuration and health diagnostics.
6. Tests proving compromised feeds and packages cannot execute prior to
   verification, plus safe defaults for all existing installations.

The PHP server plugin catalog is separate from Geode distribution.
Server-side plugins can now advertise `client_features` in MuchoClient's
discovery menu; this permission only contributes validated metadata. See
docs/MUCHOCLIENT.md for the safe bridge API and source-only Geode companion.

**No automatic third-party PHP execution is enabled by this preview.**

## Release safety

The v1.1.0 release workflow also checks endpoint certification with
PHP tests/release/v110-endpoint-gate.php before creating a stable tag.
The check fails closed until the registry covers the required P0 endpoints,
all registered endpoints have passed required quality dimensions, and
policy.certification_complete is explicitly true. The full roadmap still
requires independent VPS, backup/restore, security and API token gates.
