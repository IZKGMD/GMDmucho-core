# MuchoCore Admin — operator UX release

This is the second/final functional pass after the grouped sidebar and quick
actions (#132). It is intentionally a safe enhancement of the existing admin
controller, not a rewrite of authentication or mutation endpoints.

## Everyday management (styled and plain admin)

* Players: predictable search, status filters, visible result ranges and
  pagination; clearly explained destructive actions. Existing permission,
  CSRF and server-side validation still own all account writes.
* Levels: the Requested Stars / moderation queue excludes levels already rated
  (\`requested_stars > 0 AND stars = 0\`); rated content remains available
  under the Rated filter. List state stays in navigation and POST return fields.
* Music: a consistent empty state and search/reset controls; the client
  checks 20 MiB MP3 limits before submit (the server still validates uploads).
  Deletion continues to require confirmation.
* News: live headline/text preview uses \`textContent\`, not rendered HTML.
  Character count, image-count / image-size checks (6 files, 5 MiB each) and
  existing YouTube/image media controls remain available. In the CSS-free
  interface the editor stays a native form without required JavaScript.
* Filtered data is permission-scoped and unavailable pages never appear in
  the dashboard's quick actions.

## Monitoring

\`/admin/?page=monitoring\` is intentionally honest rather than assuming a
legacy stack. The health API:

1. Reauthenticates the current administrator and checks
   \`monitoring.view\`. Host logs additionally need \`system.manage\`.
2. Proves SQL connectivity and checks \`GET /health\`; it only probes
   read-only Geometry Dash API endpoints. A responsive endpoint is *not* a
   successful login or level upload. Protected endpoints may legitimately
   answer HTTP 400/401/403 for an empty request.
3. Reports background job failures and unresolved system alerts from existing
   tables. Missing tables return \"unavailable\", never misleading zero counts.
4. Never creates tables or updates database state on a read-only request.
5. Renders responses using DOM text nodes, not interpolated \`innerHTML\`.
   The UI never displays player IP addresses in the anomaly feed.
6. Does not run the old Nginx/PHP-FPM/Cloudflare status helper on a Docker
   deployment. Host log output is optional; use \*\*\`sudo mucho logs\`\*\* or
   \*\*\`sudo mucho doctor\`\*\* on the VPS when helpers are not installed.

Monitoring refreshes health about once per minute only when the tab is visible.
Log auto-refresh is **opt-in**. No checks issue write-capable protocol requests.

## Verification

Automated checks run in GitHub Validate:

\`php tests/application/admin-simple-integration.php\`
\`php tests/application/admin-plain-integration.php\`
\`php tests/application/admin-chrome-integration.php\`
\`php tests/application/admin-dashboard-access.php\`
\`php tests/application/admin-usability-contract.php\`
\`php tests/application/security-regressions.php\`
\`node --check public/admin/admin-workflows.js\`
\`node --check public/admin/admin-monitoring.js\`

Before a production merge, smoke-test at least: Players search and Save,
a pending level in Rating Studio, song verification, News draft preview/image
and YouTube posting, Monitoring manual refresh and permission-denied logs,
desktop 1440px / mobile 375px layouts, and the CSS-free admin.
Database changes, if any, should be backed up before deployment.

**Known limit:** CI tests rendering and contracts, but cannot sign into your
private production GDPS or visually validate every admin tab on a live
browser. Operator actions and file uploads need a manual staging smoke test.
