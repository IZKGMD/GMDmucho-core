# Personal Daily Quests

Daily Quests is a bundled first-party SDK plugin under
`plugins/builtin/mucho-daily-quests`. Updating MuchoCore includes its PHP code;
the standard migration runner creates the two quest tables. Players install one
MuchoClient 0.9.0 `.geode`, which includes every existing client module and the
new Daily Quest screen. Keep `izkgmd.muchoclient` as the mod id and replace the
old package instead of installing a second client.

## Server setup

Deploy the updated core source and run the normal MuchoCore migrations before
using the new client feature. This source change does not deploy itself to a VPS.
Daily Quests is enabled by default. Disable it with
`MUCHO_DAILY_QUESTS_ENABLED=0`; `MUCHO_PLUGINS_ENABLED=0` also disables it.
When disabled, the Daily Quest feature is not advertised and profile syncs do
not use the personal quest repository. Third-party plugins remain under the
persistent custom plugin root; built-in and custom ids share the dependency
resolver and collision checks.

## Player experience

- Three goals per UTC day: new stars, new moons and one new demon.
- Star/moon targets rotate between 5, 10 and 15; everyone receives the same
  rotation for a given day. Rewards are 25, 40 and 75 **Quest XP**.
- Progress bars, an explicit Claim button and a UTC reset countdown.
- XP persists between days; each 250 XP advances the Quest Level.
- Every module in the extension menu is reachable through pagination.

Quest XP is a separate server-held progression balance. It does not change
Geometry Dash diamonds, coins, orbs, stars, local saves or cosmetic inventory.

## Progress and reward integrity

The module records normalized counters accepted by the existing authenticated
profile score pipeline. It takes a baseline before the profile write, retains
lifetime high-water marks and caps each quest's daily progress at its goal.
Historic scores, duplicate syncs and score-reset replays do not earn progress.
The countdown and claim day are determined by the server clock in UTC.

This is **not replay-verified gameplay proof**: authenticated GD profile totals
are still client-reported. The feature inherits the core's score acceptance
model; a universal anti-cheat claim would be incorrect. The quest endpoints do
not accept client-provided `progress`, `completed`, counters or XP as evidence.

Claims hold the authenticated account, wallet and quest locks in one SQL
transaction. The unique `(account_id, quest_day, metric)` row is the reward
receipt. A repeated claim returns current state with `claimed_now=false`.
Overlapping claims cannot award twice; a storage failure rolls back both the
claim marker and the XP balance. Expired-day claims are rejected. Claim/read
operations and profile updates use a consistent account/profile/wallet order
after the existing clan lock when a clan participates.

## HTTP protocol

Both routes require HTTPS and authenticated POST fields `accountID`,
`gameVersion=22`, `gjp2` (or the compatible GD credential field).

| Route | Operation | Extra fields |
| --- | --- | --- |
| `/extensions/daily-quests` | Read current day, goals and XP | None |
| `/extensions/daily-quests/claim` | Claim a completed goal | `day=YYYY-MM-DD`, `questID=stars\|moons\|demons` |

Responses use `{"ok":true,"data":{...}}`, with `schema_version=1`, the account
id, UTC day/reset timestamp, remaining seconds, XP, Quest Level and three quest
rows. Each row includes id, title, target, progress, reward XP, claimed and
claimable flags. The module declares `min_client_version=0.9.0` in discovery.

Wrong HTTP methods return 405; failed authentication 401; unavailable storage
503 with a generic message; incomplete/expired/unknown quests return 400.
No account credential is put in plugin diagnostics or client settings.

## Validation

`tests/application/daily-quest-integration.php` uses a randomly named isolated
MariaDB database and tests authentication, baseline/delta counting, resets,
rollover, reward retry, independent concurrent score/claim workers, forced
reward rollback, UTC session-time-zone independence and bundled plugin wiring.
The test requires `TEST_DB_HOST=127.0.0.1` and a dedicated CI root password.

`tests/client/muchoclient-daily-quest-rules.cpp` tests dates, state invariants,
progress bounds, timer expiry and every navigation page. The client build CI
runs both quest tests and existing clan integration before combining the three
native builds into a single `.geode`.
