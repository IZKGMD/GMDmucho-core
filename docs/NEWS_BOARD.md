# MuchoGDPS News Board

Owner-only content management, available under `/admin/?page=news`.

## What works

- The actual MuchoCore **owner** role can create, edit, pin, publish, unpublish, or delete announcements. Other administrator roles, including moderators and custom roles, cannot access the editor or its POST actions.
- All changes use the existing HTTPS admin session, CSRF token, and audit logging. News text is escaped on display; optional links require HTTPS.
- MySQL tables `mucho_news_posts` and `mucho_news_media` are created by the normal schema migrator during deployment. Image bytes are stored in the database, so normal database backups include the news media.
- Only published posts appear in the read-only feed `GET /api/muchocore/news` (JSON; pinned entries first) and in the public browser view `/news.php`.
- Unpublished drafts are stored on the server and never appear in the public feed.

## How to publish

1. Update the server through the normal MuchoCore updater and sign into the admin panel as the owner.
2. Select **Content → News Board**.
3. Write a headline and text, add images, or paste a **YouTube video** URL. Text is optional when the post contains an image/video. Check **Publish** to make it public; optionally check **Pin to top**.
4. Save. To edit an entry select **Edit**; to change its publication status use **Publish/Unpublish**.

### Images and YouTube cards

- Attach up to **six JPG/PNG images per post**, each at most **5 MiB** and 8 million decoded pixels. Uploads are decoded and freshly encoded as JPEG, resized to fit 1600 × 1600 without changing aspect ratio, and limited to 1.5 MiB after encoding. Transparent areas become white. Original filenames, metadata and appended bytes are not retained.
- Paste a YouTube watch link, `youtu.be` URL, Shorts, embed or live-video link. The publisher normalizes it to the video ID and adds a native clickable preview with the game's Play overlay. There is one video card per post, in addition to the six images.
- The server fetches the preview from the fixed `i.ytimg.com` image origin. No API key is needed. If YouTube cannot provide the preview or that origin is unreachable from the VPS, upload a **Video preview image** manually. Unchanged saved previews are reused on subsequent edits.
- The owner can preview draft media through the authenticated admin endpoint. Public metadata/image requests only expose media attached to a published post. Unpublishing makes new public requests return 404; clients may retain images previously downloaded while the post was public.
- To replace a picture, select **Remove image on save** and attach a replacement. Clear the video URL to remove its card. A rejected edit leaves the post, media and native revision unchanged; the editor retains the entered text and URLs. File inputs must be selected again after a failed submission.
- These controls work in both styled and CSS-free admin interfaces without requiring JavaScript.

## Native GD 2.209 News integration (opt-in)

The news.dat fixture captured from a real GD 2.209 Windows installation has been decoded and used to implement its compressed wire format: URL-safe Base64 of GZIP text, header `4,<count>`, post records `1:<id>;2:<base64-date>;3:<base64-title>;4:<content-element>`, with `0` text, `1` image and `2` link content elements. The publisher supports all three element types, including posts containing only media.

The original image element is `1,<filename>,<width>,<height>,<play-overlay>,<base64url-target>`. A regular image uses overlay `0`; a YouTube preview uses overlay `1` and its canonical video URL. This is a linked preview, not an embedded browser/video player. Resource names are content hashes; replacing image bytes creates a new filename instead of reusing a stale native cache entry.

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
- `/gd-content/news/mcnews_<sha256>.jpg` — native image resources
- `/news/image.php?file=mcnews_<sha256>.jpg` — the same published image for web/JSON readers

The first media upgrade changes the Caddy service configuration to force its recreation even when an older updater is running. Subsequent updates reload Caddy after migrations/admin synchronization so changed routes become active without requiring container recreation. If the reload fails, the update checkpoint remains pending and a subsequent update retries deployment.

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
