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