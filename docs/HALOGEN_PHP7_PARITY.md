# HalogenGDPSCore comparison: PHP7 Native readiness

This is a **reference comparison, not a release certificate**. It deliberately
keeps the PHP8 primary runtime intact and does not copy Halogen internals.

## Provenance and license

- Reference: [FruitSpace/HalogenGDPSCore](https://github.com/FruitSpace/HalogenGDPSCore), `master` tree `7051c0ea02af594c93f62795ab09ddc636949b4b`.
- Halogen copyright: (c) 2023 FruitSpace, MIT license ([original](https://github.com/FruitSpace/HalogenGDPSCore/blob/master/LICENSE)). The reference inventory below uses only endpoint **filenames**, not Halogen's code. If any substantial Halogen code is later adapted, retain its MIT copyright/permission notice in the distributed source.
- Halogen's README explicitly says that **custom music does not work** in its original kernel. It is not a safe functional source of truth for MuchoCore music playback.
- Its older controllers rely on `HTTP_X_REAL_IP` and some handlers rely on IP-associated sessions. Preserve MuchoCore's trusted proxy boundary and **credential-per-request** authentication instead of copying those assumptions.

## Legacy surface comparison

Halogen `master/database/*.php` contains **78 direct endpoint filenames**.
The independently-authored test
`tests/compat/php7-halogen-legacy-routes.php` fixes that inventory at the
reference tree above and checks:

1. Each historical URL normalized through MuchoCore's **compiled PHP7 Router** resolves to a registered route in **both** `src/Core/Application.php` and `compat/php7/core/src/Core/Application.php`.
2. Compatibility alias tables in the PHP8 and compiled PHP7 Routers remain in parity, rather than silently drifting after a PHP8 change.
3. This check runs in the PHP7 Native CI matrix under **PHP 7.0 and PHP 8.3**, and in Validate.

The baseline is **78/78 known Halogen route names recognized** by both
application route registries. Examples include `getGJLevels21.php`,
`getGJMapPacks21.php`, `getGJRewards.php`, `getGJLevelScores211.php`,
and `updateGJDesc20.php` (the latter normalizes to
`updateGJLevelDesc20`).

**Important:** A registered PHP route is *not* proof that the controller
returns a correct response; for example, `getGJLevelScores211` also submits
progress and must not be classified as a harmless read-only request. This
audit is not a behavior diff, a performance test, or a real client trace.

## Must-pass acceptance before PHP7 production claims

| Area | Required evidence | Current conclusion |
| --- | --- | --- |
| Route names | Halogen historical routing and PHP8/PHP7 alias parity | Static contract included |
| Native protocol | GD 2.209 gameVersion 22, binaryVersion 51: login, levels, leaderboard, comments, social, Cloud Save; successful and negative cases | Synthetic tests exist; full real-client signoff pending |
| 5v.pl deployment | Fresh install and restart, HTTPS and `.htaccess` mapping, POST body integrity, provider resource caps and PHP 7.0 API behavior | Provider acceptance pending |
| Data safety | Restore accounts/levels/cloud saves with **original** `config/cloudsave.key`, native schema migrations, idempotent update and rollback | Requires provider-backed rehearsal |
| Security | Authentication under shared IPs, replay and spam prevention, protected private keys, no untrusted forwarded-IP bypass, admin recovery | Independent PHP7 security assessment pending |
| Client patcher | Patched Windows and Android clients verified with the *actual* target GD builds | Build-specific and real-client checks pending |
| Operations | 5v resource/time limits, concurrent requests, rate limits, recoverable failed installs | Provider budget tests pending |

PHP 7.0 is end-of-life. Tests or feature parity **cannot** make that runtime
security-supported. Keep PHP7 Native classified as experimental on 5v.pl and
prefer PHP 8.3+ for new public services.

## Next implementation order

1. Capture *sanitized* Windows and Android 2.209 request/response traces from a disposable GDPS instance on 5v.pl. Compare real results byte-for-byte for the P0 route family, not only handler names.
2. Fix confirmed PHP7 adapter differences in the original MuchoCore services and add negative/concurrency regressions for each change; avoid importing unverified historical Halogen behavior.
3. Exercise owner setup, admin access, interrupted upgrades, backups and Cloud Save restore on a disposable provider database.
4. Only then update public readiness labels and publish a stable package if the release gates allow it.

See also `docs/PHP_RUNTIME_COMPATIBILITY.md`,
`docs/PHP7_NATIVE_PHASE1.md` and
`docs/GD_2209_NATIVE_ENDPOINT_AUDIT.md`.
