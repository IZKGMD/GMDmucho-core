# MuchoClient — unified Geode companion for MuchoCore

MuchoCore is the backend of a GDPS; MuchoClient is a **single, separately
distributed Geode mod** that presents server-provided extra content directly
inside a supported modern Geometry Dash client.

## One mod, modular features

Architecture:

    Geometry Dash + Geode + MuchoClient
                   |
             HTTPS discovery
                   |
         GET /muchoclient/manifest
                   |
             MuchoCore PHP
                   |
        ClientFeatureRegistry
           /             \
        Core clans     custom PHP plugins
                            |
                      client_features SDK

The client is installed only once. Operators add new server-side modules
through the persistent custom/plugins directory; those plugins register
new in-game menu entries through a stable SDK instead of changing the Geode
binary for every feature.

## Bridge API v1

GET /muchoclient/manifest returns:

~~~json
{
  "schema_version": 1,
  "core_version": "1.1.0",
  "client": {
    "id": "izkgmd.muchoclient",
    "min_version": "0.1.0",
    "protocol": 1,
    "required_for_extensions": true,
    "required_for_legacy_gameplay": false
  },
  "features": [
    {
      "id": "clans",
      "name": "Clans",
      "description": "Clan features for authenticated players",
      "entrypoint": "/api/clans/my",
      "origin": "core",
      "requires_client": true
    }
  ]
}
~~~

A POST to /muchoclient/negotiate with fields client_version and protocol
returns a compatible boolean, upgrade status, minimum client version and
protocol number. It does not authenticate the player or issue any session
token. Client metadata can be spoofed, so **never authorize a user based on
whether a client claims MuchoClient/Geode is installed**.

## Server-plugin extension API

A plugin manifest opts into the client_features permission:

~~~json
{
  "id": "example",
  "name": "Example",
  "version": "1.0.0",
  "permissions": ["routes", "client_features"]
}
~~~

Plugin code:

~~~php
$context->route('GET', '/extensions/example', static fn() => 'Hello');
$context->clientFeature(
    'example',
    'Example',
    'Extra content from my server plugin',
    '/extensions/example'
);
~~~

Manifest data is restricted to short text, a safe ID and relative
/extensions/... paths. It cannot contain executable scripts, arbitrary
download links, untrusted domains, access tokens or passwords.

Server plugins remain trusted executable PHP; the SDK capability list is
**not** a sandbox.

## What "required" means

- The **MuchoClient extras interface** requires the MuchoClient Geode mod.
- Ordinary GDPS login, vanilla gameplay and account endpoints work without
  Geode as before.
- MuchoCore still supports legacy GD client protocol generations 1.0–2.2.
  Geode is not a universal mod loader for older binaries.
- Any privileged module action must separately authenticate a MuchoCore
  account, check authorizations and protect itself from abuse. Handshake
  version fields or User-Agent headers are not security proof.
- Operators may choose to *promote* the extras experience as their standard
  2.2 client pack in the future; that must not silently break legacy clients.

## Native clan manager v0.4.0

MuchoClient targets GD 2.2081 and Geode 5.10.1 on Windows x64, Android ARM64
and ARMv7. One combined `.geode` includes the three native builds.

The native clan manager authenticates using the current game's account ID
and GJP2. It includes open/invite-only creation, paged search, joining,
leaving, complete member lists, invitations and owner/officer management:
roles, kicking, bans, settings, ownership transfer and disbanding. All writes
are authorized by MuchoCore, regardless of the client's displayed controls.

`POST /api/clans/invites/sent` returns outgoing invitations to owners and
officers. `/api/clans/search` accepts bounded `offset` and `limit` values and
adds `has_more` to its response. Existing clients may ignore these fields.
Both the core and the companion should be updated for these new capabilities.

Controls keep a consistent scale after selection, block repeat requests
while loading, retain form values on errors and cancel pending callbacks
when the window closes. Test the installed game on real devices after native
CI compilation and the isolated MariaDB clan lifecycle tests pass.

Dynamic level objects/assets, quest UI, events timelines, client package
signatures and one-click bundled installation remain separate milestones.
The client never executes downloaded native code/JavaScript or injects
arbitrary texture assets from unaudited module manifests.
