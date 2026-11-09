# Mucho Design System

This is the original, dependency-free visual layer shared by the public MuchoGDPS site, the MuchoCore landing pages (EN and RU), the player portal and the PHP 8 administrator workspace.

## Design approach

- **One identity:** ink-blue surfaces, restrained violet interaction, cyan status accents, readable body text.
- **Reference research:** [shadcn-admin](https://github.com/satnaing/shadcn-admin) for information hierarchy, [Tabler](https://github.com/tabler/tabler) for dense data layouts and responsive admin conventions, and [Aceternity UI](https://github.com/aceternity/aceternity-ui) for the *idea* of ambient grids and light effects.
- **Independent implementation:** no third-party CSS, HTML, JS, or visual assets were copied.
- **No changes to server logic:** statistics remain live, permission checks remain server-side, forms keep their original routes and CSRF handling.
- **Performance:** no web fonts, icon CDN, build step, or JavaScript needed for the design.
- **Access:** reduced-motion support, keyboard-visible focus, contrast-aware palette, responsive layouts.
- **PHP 7:** native shared-hosting admin styles are not modified by this pass.

## Entry points

All four surfaces load `/assets/mucho-design-system.css` **last**:

1. `public/index.html`: public MuchoGDPS site (`mc-home`)
2. `public/muchocore/index.html` and `public/muchocore/ru/index.html`: MuchoCore product pages (`mc-core-site`)
3. `public/dashboard/index.php`: player portal (`dashboard-page`)
4. `public/admin/index.php`: main/admin sign-in (`mc-admin` and `admin-login`)

## QA checklist

1. Check site at 360px, 768px and desktop widths; verify menu and language switch.
2. Test player search, sign-in/out, music upload and profile browsing.
3. Test admin dashboard, Rating Studio, plugins, patcher and all restricted-role views.
4. Test dark/light toggle where available, keyboard-only navigation and reduced-motion mode.
5. Verify that real counts load; design layer never uses placeholder values.

This is a visual foundation; it intentionally avoids changing markup and business workflows.
