# MuchoCore v1.1.1 — PHP 7 native porting audit (NOT released yet)

**Baseline:** immutable `v1.1.1` PHP 8.2+ release. Do not rewrite this tag.
**Destination:** separate future PHP 7.0 native distribution for 5v.pl, after
independent real-host tests. No PHP7 changes are included in the PHP8 release.

## Port only the delta, never a blind PHP8 source copy

1. Freeze PHP8 `v1.1.1` tag and record Git SHA and SHA256 release assets.
   Compare it against the native PHP7 compiled source manifest
   `compat/php7/core/sources.json`; classify every affected module as
   unchanged, compatible, manually translated or PHP8-only.
2. Review correctness-sensitive areas individually:
   **authentication and profile identity**, **level scores and ratings**,
   **messages and new 2.209 threads**, **notifications and invitations**,
   **encrypted Cloud Save**, **News/editor/audio/reporting**, and
   **admin setup/migrations and file uploads**.
3. For each changed PHP8 file, first review behavior/SQL/response bytes,
   then implement equivalent PHP7.0 syntax in `compat/php7/core`.
   Update source fingerprints only after byte-for-byte review and tests.
   Do not copy `readonly` classes, typed properties, union/mixed types,
   `match` expressions, arrow functions, nullsafe access or PHP8-only
   APIs into PHP7.0.
4. Keep additive SQL migrations and ID/account ownership semantics;
   backup the existing DB and Cloud Save key before installing. Avoid
   client-visible response-format changes without a saved 2.209 request
   fixture.
5. Use PHP 7.0 *and* 8.3 CI for the compiled runtime, plus MariaDB fixtures,
   real PHP7 HTTP front-controller testing, and a disposable 5v.pl
   fresh install followed by safe update from the current PHP7 preview.
6. Publish PHP7 as an **explicitly separate preview release asset**
   only after these checks succeed and the real 5v.pl host can complete
   account/login, level upload/download, messages/threads and Cloud Save.
   Do not silently bundle PHP7 as a tested option in PHP8 release notes.

## Existing evidence (not sufficient to approve the port)

The current repository runs native PHP7 integration CI and isolated PHP7
shared-hosting HTTP checks. Selected GD2.209 conversations, message,
notification and cloud-save implementations have PHP7 copies and tests.
This does **not** certify every feature, the 5v.pl provider's FTP/HTTP
restrictions, or the PHP7 security posture.

## Release ordering

**Phase 1:** publish independently installable v1.1.1 PHP8 archive/tag.
**Phase 2:** write file-by-file compatibility report from exact tag.
**Phase 3:** smallest PHP7 patches and CI fixes, no Go work.
**Phase 4:** test on disposable 5v.pl and publish an explicitly preview
asset only after demonstrated success.

PHP 7.0 is unsupported upstream; recommend PHP 8.2+ for production.
