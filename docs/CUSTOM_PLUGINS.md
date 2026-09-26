# Custom Plugins

MuchoCore supports a persistent custom plugin layer for GDPS-specific features.

## Why plugins exist

Do not edit MuchoCore core files just to add functionality that is specific to your GDPS.

Use:

~~~text
custom/plugins/
~~~

Custom plugins are intentionally kept outside the tracked core source tree. They are loaded at runtime by `PluginManager` and are preserved when MuchoCore is updated from a published release.

## Installation

Create a directory:

~~~text
custom/plugins/my-plugin/
~~~

Add:

~~~text
manifest.json
plugin.php
~~~

Example manifest:

~~~json
{
  "id": "welcome-message",
  "name": "Welcome Message",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "Adds a custom welcome endpoint",
  "enabled": true,
  "permissions": [
    "routes"
  ]
}
~~~

Example plugin:

~~~php
<?php

declare(strict_types=1);

use MuchoCore\\Plugin\\PluginContext;
use MuchoCore\\Plugin\\PluginInterface;

return new class implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->route('GET', '/welcome', static function (): string {
            return 'Welcome to this GDPS!';
        });
    }
};
~~~

Restart the application container after installing or changing a plugin:

~~~bash
sudo docker compose restart app testgdps-app
~~~

## Permissions

Plugins declare the SDK capabilities they need:

| Permission | Access |
| --- | --- |
| `events` | Subscribe to MuchoCore lifecycle/request events |
| `routes` | Register custom GET/POST/ANY HTTP routes |
| `database` | Access the MuchoCore PDO connection |

Unknown permission names are ignored.

## Lifecycle events

~~~text
server.boot
plugin.loaded
request.received
request.completed
request.failed
~~~

Event payloads may evolve as the core evolves. Plugins should use only fields documented for the event they consume.

## Core updates

The intended deployment model is:

~~~text
GitHub Release
      ↓
MuchoCore updater
      ↓
tracked core source changes
      ↓
custom/plugins/ remains untouched
~~~

The updater deploys the exact published stable release tag with `git reset --hard`. Custom plugin files are not tracked by the core release and are therefore preserved.

Do not put custom plugins in `src/`, `public/`, `database/` or other core directories.

## Configuration

Plugins are enabled by default:

~~~text
MUCHO_PLUGINS_ENABLED=1
~~~

Disable all plugins:

~~~text
MUCHO_PLUGINS_ENABLED=0
~~~

The default plugin directory is:

~~~text
custom/plugins/
~~~

It can be overridden for advanced deployments:

~~~text
MUCHO_PLUGIN_DIR=/opt/my-gdps/plugins
~~~

## Compatibility

A plugin should remain compatible with the MuchoCore SDK API it targets. Keep plugin code isolated from internal implementation classes whenever possible.

## Security

Plugins execute as part of the PHP application. SDK permissions are an API-level restriction, not a process sandbox. Treat third-party plugins like any other executable server-side code and review their source before installation.
## Compatibility

Plugins support explicit compatibility guards so a custom extension can survive core updates without being loaded against an unsupported API or core version.

Example:

```json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.4.0",
  "api": 1,
  "min_core_version": "1.0.3",
  "max_core_version": "1.9.0",
  "enabled": true,
  "permissions": ["events", "routes"]
}
```

- `api` is the MuchoCore Plugin SDK API generation. Current supported value: `1`.
- `min_core_version` prevents loading on older cores.
- `max_core_version` prevents loading after a declared upper compatibility boundary.
- All version guards use `MAJOR.MINOR.PATCH`.
- Older manifests without these optional fields remain compatible with Plugin SDK API 1.

An incompatible plugin is skipped instead of being executed. This is especially useful when a GDPS keeps custom plugins in `custom/plugins/` while MuchoCore itself is updated from a stable release.

## Diagnostics

The core can inspect plugin manifests without executing plugin code. The Admin Panel uses this information to show compatibility and configuration problems such as disabled plugins, missing entrypoints and unsupported core/API versions.

This diagnostic path is read-only and does not modify plugin files.

