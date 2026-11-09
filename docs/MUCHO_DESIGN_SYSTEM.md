# MuchoCore Studio UI · v2

The first design pass used glow, gradients, rounded floating cards and colored typography. That made the project look like a generic generated dashboard. This revision **replaces** the design stylesheet rather than stacking more effects on top of it.

## Source review / design decisions

- [Midday](https://github.com/midday-ai/midday) — information-first workspace density and composed surfaces. Take the *layout discipline*, not source code.
- [OpenStatus](https://github.com/openstatusHQ/openstatus) — hierarchy for live data and clear system status. Keep readable labels and nondecorative data.
- [shadcn/ui](https://github.com/shadcn-ui/ui) — coherent control geometry, accessibility and consistent focus feedback. Use one 6–7px radius scale.
- [Tabler](https://github.com/tabler/tabler) — predictable administrative navigation and tight, legible tables.

The MuchoCore result is original CSS, no npm/framework imports, copied components or assets.

## Rules

1. Dark graphite (#101113) with warm white ink; single pale green action accent (#d2e58a), never a purple-to-cyan gradient.
2. No glow, glassmorphism, radial gradients, animated illustrations, inflated cards or hover lift.
3. Hero typography is plain and substantial; technical diagrams look like data panels.
4. Where cards serve only as section separators, use hairline borders instead.
5. Admin/data pages are compact; actions and states are more important than decoration.
6. Preserve the original PHP forms, endpoints, semantics, translations, accessibility and live data.
7. The product landing background remains #0c0d12 to keep the verified desktop/mobile contrast stable.
8. Support narrow screens, keyboard focus, light theme and reduced motion.

## Entrypoints

The shared stylesheet `public/assets/mucho-design-system.css` is loaded last by the public site, MuchoCore EN/RU, admin, portal, clans, installer and documentation pages. Existing runtime CSS provides page structure; this layer harmonizes presentation without changing server logic.

## Acceptance

Run `node --test tests/application/mucho-design-system.test.cjs`, `python3 tests/seo/muchocore_visual.py`, PHP 7/8 tests, and manual views of login, admin lists, portal profile and narrow navigation. No deploy until the PR is merged.
