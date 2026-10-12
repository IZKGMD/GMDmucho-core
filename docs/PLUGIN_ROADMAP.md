# MuchoCore plugin roadmap

These are product proposals, not claims that all modules are implemented.
The current extension transport exposes validated menu entries and a JSON
title/message view. Rich interactive screens need a MuchoClient update; future
MuchoLoader integration must retain that protocol deliberately.

| Priority | Plugin | Player value | Initial implementation |
| --- | --- | --- | --- |
| Included example | Mucho News | Announcements and event details inside the game | Configured public JSON; works with the existing client view |
| Included bundled module | Daily Quests | Three daily goals and Quest XP | Authenticated accepted-profile deltas, lifetime high-water marks, transactional claims and a screen in the unified client; profile totals are not replay-verified gameplay proof |
| Next | Creator Hub | Submit a level for review and track the decision | Per-account submission limits, moderator queues, decision history and an interactive client screen |
| Later | Clan Wars | Competition between the clans already in MuchoCore | Scheduled rounds, snapshots of verified scoring events, clan membership rules and standings |
| Later | Season Pass | Seasonal cosmetic progression | Shared quest/reward foundation, explicit season boundaries, cosmetic inventory and audited claims |
| Later | Collections | Curated level sets and completion tracking | Curator workflow, versioned level sets and validated player progress |
| Later | Replay Review | Evidence for disputed scores and moderation | Bounded uploads, explicit evidence provenance and moderator tools; no promise of universal anti-cheat |

Build the account/reward foundation once, then share it between Quests, Clan
Wars and Season Pass through declared dependencies. Administrative installation,
package verification, persistent health monitoring and client pagination are
separate follow-up work, not hidden behavior in this runtime update.
