# MuchoCore vs NitroCore vs HalogenCore: GD 2.209 and performance audit

**Date:** 2026-11-18. Source snapshots: MuchoCore `main` at
`ddd1b08967a6e13a99af726ac6737aef5f55cdc8`;
NitroCore `main` tree `506fdafa8b1ad30e84cc287d3b633672f0f887c9`;
HalogenCore `master` tree
`7051c0ea02af594c93f62795ab09ddc636949b4b`.

This document describes **audited source patterns** and concrete testing
requirements, not a claim of production or real-client certification.

## Reference decisions

| Reference | Worth adopting independently | Avoid copying |
| --- | --- | --- |
| [NitroCore](https://github.com/RigbyHost/NitroCore) | Bounded multi-ID music lookup; strict input validation; plugin/event design; optional performance instrumentation | NitroCore is TypeScript/Drizzle, targets GD **1.9–2.206** in its README, and uses **GPLv3**. Its code is not a PHP7 drop-in and was **not copied** |
| [HalogenGDPSCore](https://github.com/FruitSpace/HalogenGDPSCore) | Small, clear functional divisions and older route inventory | The reference's account/IP fast path, untrusted `HTTP_X_REAL_IP` assumptions, unbounded/report-only requests and hosting-dependent music cannot replace MuchoCore safeguards. Halogen uses **MIT**. The original README reports that custom music does not work |
| MuchoCore | Keep per-request credentials, PDO prepared queries, role checks, Moderation, MuchoProtect, cache, player privacy, native 2.209 evidence gates | Optimize specific hot paths without weakening the above guarantees |

## The verified Windows client

Uploaded `GeometryDash.exe` is a Windows x64 PE; **13,178,880 bytes**;
SHA256 `4eee06d5c2e2213b4ba2b1f6233dd426bbdd0e9b29d7c375e499516b6f8f69d5`.
The hash matches the known 19 Oct 2026 build already referenced in
`tests/client/gd-2209-synthetic.php`.

Static strings (no executable code run, no game binary redistributed)
show **111 unique** `www.boomlings.com/database/...php` endpoint filenames,
two additional `backupGJAccountNew.php` / `syncGJAccountNew.php`
filenames and a malformed `databas/checkIfServerOnline.php` reference,
for a **114-name reference surface**.

`tests/compat/php7-gd2209-client-route-inventory.php` guards those
filenames on both the original PHP8 `Application.php` route registry and
the PHP7 compiled registry. The exception is the two web account-recovery
pages, which must be served by the canonical recovery controller as files
or Caddy rewrites rather than returning a Geometry Dash API sentinel.
Caddy already rewrote those exact paths; now Apache also has physical
compatible aliases under `public/database/accounts/`. PHP7 `php7.php`
already handles the routes after its completed migration.

**A route matching a filename does not prove a working endpoint.** In
particular, `getGJAudioFilesize`, `getGJContentStatus`, messages and
moderation require native 2.209 request/response traces to confirm exact
wire encodings and error replies. Do not mark these certified because
a static test passed.

## Measurable improvement in this PR: GD 2.209 audio size lookup

Before: `Gd2209UtilityService::audioSizes()` issued one SQL statement
per song ID and per SFX ID, up to **128 SELECT queries** per request.

After: `loadAudioSizes()` selects rows in **at most two bounded
parameterized IN queries** (one songs table, one editor sounds table).
It retains:

- input ID validation and 64-per-type bound;
- deduplication and **request order**, not the unordered DB row order;
- `songs.size` MiB-to-byte conversion and `size_bytes` passthrough;
- rejection for missing, unverified or impossible sizes (no fabricated data);
- the existing provisional `songs#sfx` response envelope until native
  client parser evidence is captured;
- the same logic in compiled native PHP7 and canonical PHP8.

The `tests/application/gd2209-audio-batch-sqlite.php` fixture counts actual
prepared statements. With **64 song IDs plus 64 sound IDs**, the expected
SQL statement count is **2**. This is a query-count improvement, **not** a
measured CPU or latency speedup on the actual 5v.pl environment.

## Next data-driven performance candidates

1. `src/Score/LevelScoreController.php`: `leaderboard()` calls
   `UserRepository::getProfileByTarget()` once per row (up to 100 rows).
   Profile batch-loading is a promising next optimization, but requires
   real MariaDB, friend-only leaderboard, banned-user, ordering and
   exact-wire parity tests before changing production SQL.
2. Level list path `src/Level/LevelRepository.php` already joins
   users and songs in one query, avoids LONGTEXT, and can skip COUNT on
   incomplete first pages. Avoid replacing this with older Halogen patterns.
3. `RequestProfiler` already measures protected level-list stages.
   Collect latency and SQL evidence for additional 2.209 endpoints before
   expanding fast paths or caches.
4. PHP7 shared hosting must be measured on a **disposable** 5v.pl database.
   Never assume Docker CI proves provider performance or public safety.

## Remaining release gates

- Real Windows **and** Android 2.209 session traces (authenticated and
  invalid credentials), covering P0 and newly observed endpoints.
- Complete real-host account recovery, PHP7 native installation,
  secure upgrades, cloud-save key restore and admin access.
- SQL EXPLAIN/index budgets and concurrent read/write tests with real data.
- PHP7.0 is obsolete and outside upstream security support. An
  experimental PHP7 package must not be promoted as secure production.

See `docs/HALOGEN_PHP7_PARITY.md`,
`docs/GD_2209_NATIVE_ENDPOINT_AUDIT.md` and
`tests/protocol/endpoint-contracts.json` for baseline status.
