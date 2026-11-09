# MuchoCore / Structure-First Redesign (v3)

This design is a real rewrite of the page *structure*, not a layer of styling over the same boxes.

## Verified design references

The work reviewed original GitHub sources and principles, not CSS copied from templates:

- [Midday](https://github.com/midday-ai/midday): workspace prioritization, information density, clear operator actions.
- [OpenStatus](https://github.com/openstatusHQ/openstatus): text-led operational status, scrollable high-signal data tables.
- [shadcn/ui](https://github.com/shadcn-ui/ui): accessible states, tabbed keyboard interactions and consistent control spacing.
- [Tabler](https://github.com/tabler/tabler): compact menus, readable administrative data and table affordances.

The resulting MuchoCore visual language is its own: warm off-white editorial pages, dense charcoal preview and functional panels, terracotta accents and thin dividers. No decorative gradients, ambient glows, floating cards, expensive external fonts or copied third-party components.

## Actual structural changes

### MuchoCore site, EN and RU

- Rebuilt entire HTML body for **both locales**, preserving SEO canonical/hreflang/schema, project facts and exact user-authored quote.
- Independently styled **first-party** `mucho-product-v3.css`; the old two stylesheet overlays are no longer loaded.
- Accessible three-tab specimen explains protocol request routing, operator tools and deployment *without fake live statistics*.
- A numbered feature list, request pipeline, qualified compatibility section, two real installation entry points, inline source CLI and FAQ.
- Mobile navigation, keyboard-switchable tabs, copy source command with fallback.

### PHP 8 admin

- New active-instance context and player portal shortcut in the shell.
- New overview renders a **real pending-music review queue**, live account/level/music statistics, actual latest players and songs as tables, and permission-gated operator action list.
- Audit feed identifiers and polling script are retained. All actions continue through existing controller code and CSRF/roles.
- New `mucho-console-v3.css` is loaded last; old admin modules retain fallback structural styles.
- Existing first-admin and login actions remain intact. No PHP7 implementation rewritten.

### Player portal

- Replaced the marketing hero with a **search-first discovery screen**; relocated real server figures under the search and preserved the existing account login/upload form as a useful right-side panel.
- Rebuilt spotlight around the actual current top player or honest empty state; real leaderboard, profiles, clans and music still use the existing query logic.
- New `mucho-portal-v3.css` adjusts portal and clan layouts, light/dark themes and mobile states.

### Installation and knowledge pages

- Installation chooser rebuilt as three *action-oriented rows*, not cards, retaining original VPS, manual and shared hosting routes.
- About/compatibility now share a stand-alone editorial documentation stylesheet instead of the old glowing layer.

## Architecture, caching and testing

- Original PHP service functions, endpoint registration, database tables, server secrets, login/CSRF and permissions are unchanged.
- PHP7 provenance SHA-256 manifests updated for changed PHP UI sources.
- Assets are versioned to avoid stale browser caches.
- Browser checks inspect mobile (360/390/430/768) and desktop (1440), EN/RU, menu, tab interactions, hover-independent access, viewport overflow and JS errors.
- Node structure tests and PHP7/8 CI must be green before merge.
- No direct VPS rollout: PR review and merge come first.
