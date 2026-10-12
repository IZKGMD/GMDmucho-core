# Mucho News

Adds a **Server News** item to the MuchoClient extension menu. Players see the
configured announcement inside the existing client, without a separate mod.

## Install

After updating MuchoCore with the plugin-runtime upgrade:

```sh
cp -R examples/plugins/mucho-news custom/plugins/mucho-news
```

The custom plugin directory survives core updates. Leave `manifest.json` and
`plugin.php` as supplied and create `custom/plugins/mucho-news/config.json`:

```json
{
  "title": "MuchoGDPS News",
  "message": "Creator contest this weekend! Submit your level through the server website."
}
```

Settings are re-read on the next request; restarting the server is unnecessary.
The title is limited to 128 UTF-8 bytes and the announcement to 4096 bytes.
The current client's text popup truncates titles to 55 bytes and bodies to 650
bytes, so keep announcements short; the HTTP API retains the full message.
The JSON must be an object, not a list. Do not store private information here:
the selected title and message are served publicly.

Check `GET /extensions/news` and the client discovery manifest at
`GET /api/client/manifest`. The original MuchoClient menu displays at most five
plugin items, so keep News among the first five until client pagination lands.

This plugin publishes announcements only. It does not schedule notifications,
award rewards or send player messages automatically.
