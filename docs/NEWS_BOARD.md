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

## Geometry Dash 2.209 native News tab: integration pending

The official Windows 2.209 client contains the resource paths `/news/news.dat` and `/news/news_version.txt` in its binary. These are **not** PHP API endpoints. The current Windows URL patcher only changes identified `.php` endpoint URLs, leaving those news resource lookups untouched. We have not verified the native file format, host resolution, or refresh protocol with actual traffic.

**Do not claim that the in-game News tab is populated by this feature yet.** The owner publishing system, public web view, and public feed are functional server-side once deployed. Native client integration requires a captured valid `news.dat` payload/version response, a tested formatter, and a safe patch/loader for the resource host (Windows and Android separately). Avoid shipping arbitrary JSON as native `news.dat`.

## API

`GET https://muchogdps.space/api/muchocore/news`

Example when no posts are published:

```json
{"version":1,"posts":[]}
```

No passwords, administration sessions, drafts, or admin metadata are returned.
