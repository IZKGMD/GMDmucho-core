# Custom Plugins

This directory is for GDPS-specific MuchoCore plugins.

**Everything inside this directory is treated as custom installation state. Core updates do not replace or reset plugin files.**

## Plugin layout

~~~text
custom/
└── plugins/
    ├── my-plugin/
    │   ├── manifest.json
    │   └── plugin.php
    └── another-plugin/
        ├── manifest.json
        └── plugin.php
~~~

## Manifest

~~~json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "Example MuchoCore plugin",
  "enabled": true,
  "permissions": [
    "events",
    "routes"
  ]
}
~~~

## Plugin entry point

`plugin.php` must return an implementation of `MuchoCore\\Plugin\\PluginInterface`:

~~~php
<?php

declare(strict_types=1);

use MuchoCore\\Plugin\\PluginContext;
use MuchoCore\\Plugin\\PluginInterface;

return new class implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->on('server.boot', static function (array $payload): void {
            error_log('[My Plugin] loaded');
        });

        $context->route('GET', '/my-plugin', static function (): string {
            return 'My Plugin is working!';
        });
    }
};
~~~

## Available events

- `server.boot`
- `plugin.loaded`
- `request.received`
- `request.completed`
- `request.failed`

## Updating MuchoCore

Plugins live outside the core source tree:

~~~text
MuchoCore core
    ↓
stable release
    ↓
update.sh
    ↓
core files updated

custom/plugins/
    ↓
left untouched
~~~

This means you can customize a GDPS without editing `src/`, `public/` or other core files just to add server-specific behavior.

## Important security note

A plugin is PHP code executed by the MuchoCore application. The manifest permissions control what the MuchoCore SDK exposes through `PluginContext`, but PHP itself is not a sandbox. Only install plugins whose source you trust.