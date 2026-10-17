# MuchoGDPS News Board

Owner-only content management, available under `/admin/?page=news`.

## What works

- The actual MuchoCore **owner** role can create, edit, pin, publish, unpublish, or delete announcements. Other administrator roles, including moderators and custom roles, cannot access the editor or its POST actions.
- All changes use the existing HTTPS admin session, CSRF token, and audit logging. News text is escaped on display; optional links require HTTPS.
- MySQL table `mucho_news_posts` is created by the normal schema migrator during deployment.
- Only published posts appear in the read-only feed `GET /api/muchocore/news` (JSON; pinned entries first) and in the public browser view `/news.php`.
- Unpublished drafts are stored on the server and never appear in the public feed.

## How to publish

1. Update the server through the normal MuchoCore updater and sign into the admin panel as the owner.
2. Select **Content → News Board**.
3. Write a headline, body, and optional HTTPS link. Check **Publish** to make it public; optionally check **Pin to top**.
4. Save. To edit an entry select **Edit**; to change its publication status use **Publish/Unpublish**.

## Native GD 2.209 News integration (opt-in)

The news.dat fixture captured from a real GD 2.209 Windows installation has been decoded and used to implement its actual compressed wire format: URL-safe Base64 of GZIP text, header `4,<count>`, post records `1:<id>;2:<base64-date>;3:<base64-title>;4:<content-element>`, with `0` text, `1` image and `2` link content elements. The current publisher generates text, with an optional hyperlink; image upload and hosting are not included.

### VPS activation

After updating to a revision containing this feature, opt in to replacing the GD custom content host URL with the server-hosted `/gd-content` prefix:

```sh
sudo sed -i '/^MUCHO_NATIVE_NEWS=/d' /opt/mucho-core/.env
printf 'MUCHO_NATIVE_NEWS=1\n' | sudo tee -a /opt/mucho-core/.env
cd /opt/mucho-core
sudo docker compose up -d --remove-orphans
sudo docker compose exec -T caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
```

Do not modify `MUCHO_ACCOUNT_URL`: it is reused to form the `https://<account-host>/gd-content` address. The game discovers the address via `getCustomContentURL.php`. The Caddy proxy forwards unrelated CDN resources to the fixed official `geometrydashfiles.b-cdn.net` upstream to avoid breaking music libraries and SFX when the base URL changes. **This increases traffic through your VPS**, and depends on the upstream CDN remaining reachable.

The native in-game files are then served at:
- `/gd-content/news/news.dat` — GZIP + Base64URL feed
- `/gd-content/news/news_version.txt` — monotonically increasing database version, advanced when the owner saves, publishes, unpublishes or deletes a news entry
- `/news/news.dat` and `/news/news_version.txt` — identical direct routes for checking

### Checks

```sh
curl -fsS https://muchogdps.space/news/news_version.txt
curl -fsS https://muchogdps.space/news/news.dat | head -c 100
curl -fsS https://muchogdps.space/database/getCustomContentURL.php
```

The content URL must point at `https://muchogdps.space/gd-content` after the opt-in flag reaches the PHP app container. To verify in-game, close GD, rename `%LOCALAPPDATA%\GeometryDash\news\news.dat` as a backup, start the GDPS client, open News and compare titles to the admin board. Do **not** delete player saves. No Windows binary re-patch is required for the native news URL path if GD uses the custom-content URL as expected.

**Verification status:** Wire-format conversion is covered by regression tests and real sample parsing. The custom-content URL ↔ native News request chain is based on the 2.209 client's static resource paths; the actual patched game must still be tested at runtime on Windows. Android is not yet confirmed. Do not claim full runtime compatibility until this smoke test passes.

## API

`GET https://muchogdps.space/api/muchocore/news`

Example when no posts are published:

```json
{"version":1,"posts":[]}
```

No passwords, administration sessions, drafts, or admin metadata are returned.
