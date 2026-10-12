# Plugin SDK

MuchoCore includes a lightweight PHP Plugin SDK for extending the server without modifying core protocol controllers.

## Plugin layout

Create a directory under `custom/plugins`:

    custom/plugins/
      my-plugin/
        manifest.json
        plugin.php

manifest.json defines the plugin name, version, enabled state and permissions.

plugin.php must return an object implementing `MuchoCore\Plugin\PluginInterface`.

## Permissions

The SDK supports:

- events: subscribe to lifecycle/request events
- routes: register plugin HTTP routes
- database: access the MuchoCore PDO connection
- client_features: advertise a validated section in MuchoClient

`PluginManager::fromEnvironment()` also discovers bundled modules under
`plugins/builtin`; custom modules remain under `MUCHO_PLUGIN_DIR` or
`custom/plugins`. Both roots share ids, dependency ordering and collision checks.

A plugin receives only the permissions declared in its manifest.

## Events

Available events include:

- plugin.loaded
- server.boot
- request.received
- request.completed
- request.failed

Listener exceptions are isolated and logged so a broken plugin cannot turn into a server protocol failure.

## Custom routes

A plugin with the routes permission can register an API route:

    $context->route('GET', '/my-plugin/hello', static function (): string {
        return 'hello';
    });

A handler may also return a native MuchoCoreHttpResponse.

## Configuration

Plugins are enabled by default.

Disable all plugins with:

    MUCHO_PLUGINS_ENABLED=0

Move the plugin root with:

    MUCHO_PLUGIN_DIR=/opt/mucho-plugins

The SDK permission manifest is an API contract, not a process sandbox. Plugins execute as application PHP and should therefore only come from trusted sources.

### Per-plugin settings

Put defaults in the manifest's `config` object. Operators can override top-level
keys in a `config.json` object beside `plugin.php`; this file lives in the custom
plugin directory and survives core updates. Overrides are limited to 64 KiB.

```php
$allSettings = $context->config();
$title = $context->config('title', 'Default title');
```

The override merge is shallow. A nested override replaces its entire top-level
value. No configuration is automatically exposed in client discovery; each
plugin chooses which public data its routes return. Plugins must validate the
types and limits of settings they consume.

### Dependencies and load order

Declare required plugins by exact id. Both version boundaries are inclusive;
omitting a boundary leaves that end unrestricted.

```json
{
  "dependencies": {
    "mucho-economy": { "min_version": "1.0.0", "max_version": "1.9.9" },
    "mucho-news": {}
  }
}
```

Providers register before consumers regardless of directory names. Missing,
disabled, incompatible or failed providers prevent dependent PHP from running.
Duplicate ids, dependency cycles, inverted ranges and malformed declarations
produce diagnostics rather than an arbitrary load order. A plugin may declare
at most 64 dependencies. Dependency versions use `MAJOR.MINOR.PATCH`; ranges do
not use Composer's `^`, `~` or comparison-expression syntax.

### Registration rollback and lifecycle

If `plugin.php` or `register()` throws, routes, event subscriptions and client
features are restored to their state before that plugin. Healthy independent
plugins still load. Database writes, files, network calls and other direct PHP
side effects are **not** rolled back: keep registration declarative and perform
such work only in an explicit authenticated operation or migration.

`load()` and `boot()` are idempotent for one manager instance. The next normal
request creates a new manager and reads current manifests/settings again.

Before loading, diagnostics report readiness and dependency problems without
executing PHP. After loading, the same manager additionally reports `loaded`,
`failed` and runtime `blocked` states. The read-only admin page creates its own
diagnostic manager, so its statuses are preflight information, not a persistent
runtime health monitor. Disabled plugins stay disabled; this does not grant
permission to run or install arbitrary PHP from the admin page.

See [Mucho News](../examples/plugins/mucho-news/README.md) for a working module
and [plugin roadmap](PLUGIN_ROADMAP.md) for proposed extensions.

`clientFeature()` accepts an optional fifth `minClientVersion` argument, using
`MAJOR.MINOR.PATCH`; the default is `0.1.0`. MuchoClient 0.9.0 filters modules that
require a newer client. New gameplay/UI capabilities still require a client
update, packaged into the same single `.geode`; metadata cannot execute new PHP
or native code inside Geometry Dash.


### Request event privacy

`request.received`, `request.completed` and `request.failed` expose a sanitized `MuchoCore\Http\Request` snapshot. Only a small whitelist of non-secret protocol fields is included; credentials, cookies, forwarded headers and arbitrary request parameters are omitted. `request.completed` exposes a sanitized `MuchoCore\Http\Response` with status and content type only; the response body is omitted. `request.failed` exposes the exception class name instead of the original exception object or message.
## Route ownership

Core protocol routes are reserved by MuchoCore. Plugin routes are registered after the core routes and cannot shadow an existing core or plugin route. Route collisions are rejected instead of silently replacing an existing handler.

For ecosystem-specific APIs, prefer a plugin-owned namespace such as `/api/plugin/<plugin-id>/...`.
