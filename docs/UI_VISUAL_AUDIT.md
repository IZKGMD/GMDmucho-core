# Visual QA workflow for MuchoCore

MuchoCore has two different kinds of UI checks. Do not confuse them.

## Offline visual fixtures (safe for public CI)

`tests/seo/muchocore_workspace_visual.py` renders the original source styles with blank/sample modules, including the PHP 8 administrator workspace, login and player portal. The data in these fixtures is not a copy of a live GDPS database.

For local design exploration, screenshots can also be generated from source-template previews of all 30 native PHP 7 administrator page keys (desktop 1440×900 and mobile 390×844). These are **CSS layout previews**, not proof that the real database-backed pages were rendered correctly.

The visual-polish CSS in `public/admin/admin-workspace.css` is shared by the modern administrator UI and the native PHP 7 5v.pl build. Do not add another competing stylesheet for every page. The native asset hashes in `compat/php7/admin-ui/upstream-sources.json` and stylesheet cache versions need to be updated when this CSS changes.

## Full real page capture (owner's machine only)

A fully authenticated screenshot audit cannot safely run from a public CI service without an owner session. The local audit utility uses the real installed site and captures each PHP7 admin page after you sign in manually.

```powershell
npm install --no-save playwright
npx playwright install chromium
$env:MUCHO_VISUAL_BASE_URL='https://muchocoretest.5v.pl'
node tools/visual-audit/capture-live.mjs
```

After the Chromium window opens, sign in as the site **owner**, finish any pending migration, and return to the terminal to press Enter. The tool then performs **GET requests only** to every declared admin page, plus discovered navigation links, at desktop and mobile sizes. It also captures the login screen using a fresh unauthenticated context.

It never copies the session cookies, prompts for credentials in the terminal, submits a migration or uploads screenshots. The output folder is created outside the repository, in `~/MuchoCore-Visual-Audit/<timestamp>/`, and contains screenshots and `report.json`.

**Private-data caution:** Live screenshots can contain usernames, email addresses, messages, private server settings and other personal data. Keep this folder private; inspect and redact it before sharing any images or attaching them to issues.

The report flags pages that could not be loaded, document-level overflow and missing images. It does not certify GD 2.209 protocol behavior, backend APIs or database migrations. Complete acceptance still needs a real game client and an authorized operator.

## Scope for further audit

The administrator sweep covers the owner-visible routes defined in the PHP7 admin page registry, including Server, Dashboard, Levels, Players, Rating Studio, Messages, Music, News, Client Patcher, Settings, backups, system tools and advanced modules. The PHP 8/8.3 player portal, first-run installer and account-bound views require separate authenticated screenshots/fixtures because they are distinct interfaces and have different access permissions.

Make fixes in small coherent batches: reproduce a visible bug, inspect both light/dark modes, verify 1440/980/650/390/360 widths, check keyboard focus and overflow, and run `tests/seo/muchocore_workspace_visual.py` and PHP7 original-admin tests before merging.
