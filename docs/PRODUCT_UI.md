# MuchoGDPS product interface

The canonical homepage presents MuchoGDPS, MuchoCore and MuchoClient as one ecosystem. Players can open the player portal, clans and published client downloads; server owners can choose the existing VPS or shared-hosting deployment flow. The root remains host-isolated by the existing Caddy rules.

`public/assets/product-ui.css` supplies shared dark/light colors, focus states, reduced-motion support and workspace controls. `product-home.css` owns the responsive landing composition. The homepage has Russian and English copy and obtains community counts/recent levels from the existing public dashboard API. Loading, empty and unavailable states remain explicit; no infrastructure measurements or community numbers are fabricated.

`public/admin/product-admin.css` styles the existing administration pages without replacing their forms, CSRF checks or action dispatchers. Ctrl/Cmd+K opens quick navigation built only from RBAC-filtered sidebar links. `/` focuses the authorized record search. Recent navigation and sidebar/theme choices are optional local preferences and tolerate unavailable browser storage. Table filtering searches rows of the current page, preserves table headers and does not change pagination or bulk selection.

Dashboard cards, feeds and links follow the administrator's page permissions. Audit polling requires `audit.view`; server operations require `system.manage`. Public community data excludes disabled/banned accounts and deleted/unlisted levels. Audit polling has a timeout, does not overlap requests and pauses in hidden tabs.

Player profiles display the existing MuchoClient avatar and 960 × 320 cover when available. `/profile-image.php` accepts only GET/HEAD, a bounded account ID, `avatar`/`banner` and a SHA-256 version. It reuses the existing image service, including active/non-banned account checks, and revalidates cached pictures so removed artwork cannot persist indefinitely. The in-game POST API stays intact. No new database migration is required.

Checks: `node --test tests/application/product-ui.test.cjs`, `php tests/application/admin-dashboard-access.php`, `php tests/application/public-overview-visibility.php` and `php tests/application/web-profile-images.php` (the latter two require PDO SQLite; image endpoint tests require installed Composer dependencies). These are included in Validate CI. Browser layout/interaction verification remains a separate check when a browser preview is available.
