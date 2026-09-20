# MuchoCore Site Builder

MuchoCore includes a ready-made public website for a GDPS project.

The owner does not need to write HTML to customize the basic site.

## Where it is

https://YOUR-DOMAIN/admin/?page=settings

The menu item is called Site Builder.

## What can be changed

- Site name
- Tagline
- Description
- Logo text
- Two main theme colors
- GitHub link
- Discord link
- Telegram link
- Client download link
- Project copyright text

The page validates the values before saving them.

## How it works

The public page is /public/index.html

The page loads its safe public configuration from /api/v2/site.php

The Site Builder stores overrides outside the source tree:

/var/lib/muchocore-control/settings.json

This means a normal source update does not overwrite the site's custom settings.

## Copyright

The project owner can set their own project copyright text.

The generated footer also keeps the MuchoCore attribution:

Powered by MuchoCore · Core copyright © 2026 IZK · MIT License

This is separate from the repository's MIT license. See COPYRIGHT.md and ../LICENSE.

## Manual .env values

MUCHO_SITE_NAME=My GDPS
MUCHO_SITE_TAGLINE=Welcome to my server
MUCHO_SITE_DESCRIPTION=My Geometry Dash private server.
MUCHO_SITE_LOGO=MyGDPS
MUCHO_SITE_ACCENT=#7768ff
MUCHO_SITE_ACCENT2=#43d7cf
MUCHO_SITE_GITHUB_URL=https://github.com/example/project
MUCHO_SITE_DISCORD_URL=https://discord.gg/example
MUCHO_SITE_TELEGRAM_URL=https://t.me/example
MUCHO_SITE_CLIENT_URL=https://example.com/client.zip
MUCHO_SITE_COPYRIGHT=Copyright © 2026 My Project

For a VPS installation, the admin panel is the easier way to edit these values.