# Plugin SDK

MuchoCore includes a lightweight PHP Plugin SDK for extending the server without modifying core protocol controllers.

## Plugin layout

Create a directory under plugins:

    plugins/
      my-plugin/
        manifest.json
        plugin.php

manifest.json defines the plugin name, version, enabled state and permissions.

plugin.php must return an object implementing MuchoCorePluginPluginInterface.

## Permissions

The SDK supports:

- events: subscribe to lifecycle/request events
- routes: register plugin HTTP routes
- database: access the MuchoCore PDO connection

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
