# MuchoCore PHP runtime compatibility

Geometry Dash **client protocol versions** and server-side **PHP versions**
are separate compatibility dimensions. A GD 2.209 route is not certified by
compiling successfully on PHP 7.0.

| PHP runtime | Correct deployment | Acceptance status |
| --- | --- | --- |
| 8.3–8.5 | Main MuchoCore on VPS or compatible shared hosting | Supported; genuine client/provider acceptance remains a separate gate |
| 8.2 | Main MuchoCore on shared/VPS hosting | Supported in CI; upgrade to a security-supported runtime when possible |
| 8.0–8.1 | None | Unsupported |
| 7.0 (5v.pl) | **Separate PHP7 Native preview** in `compat/php7/` | Experimental. PHP 7.0 is end-of-life; not certified production-safe |

## 5v.pl / PHP 7.0: use the actual Native distribution

The current repository has a native PHP 7 port, not just the older edge proxy.
It runs without Composer or a VPS on the shared host and uses its own PDO/MySQL
connection. Its separate archive includes compiled-compatible application
services and the original admin UI. It is **not yet completely feature-parity
or real-client certified**. Read [PHP7 native installation](PHP7_NATIVE_PHASE1.md)
and the [README](../README.md) for the download, update and live validation steps.

Do not install the normal `MuchoCore-vX.Y.Z-shared-hosting.zip` on a PHP 7
host: it requires PHP 8.2+. Do not modify `composer.json` to bypass the
version check.

The earlier `tools/hosting/5v-edge.php` relay is a **legacy alternative**
that uses a separately operated modern server. The archival
[edge documentation](HOSTING_5V_EDGE.md) describes that different deployment;
it is NOT the PHP7 Native installation guide.

## Cloud Save and backups on PHP7 Native

New pre-migration Cloud Save writes are encrypted and authenticated using a
PHP 7.0-compatible AES-256-CBC encrypt-then-HMAC format. Older plaintext rows
remain readable to permit safe upgrades; completing the migration re-encrypts
them into canonical AES-256-GCM records. **Until all old rows are migrated,
some legacy records can remain plaintext at rest.**

Back up both the database **and** `config/cloudsave.key`. Never replace the key
on upgrade or after corruption: previous encrypted records would become
unreadable. Treat private credentials, recovery material and hosting
limitations as a security issue regardless of a green syntax check.

## Verification and release policy

- The PHP 7.0 native CI matrix executes syntax, isolated integration and
  synthetic HTTP/database tests. They are **not** a production acceptance test.
- Verify the provider's HTTPS, Apache rewrites, DB limits, real client login,
  level upload/download, comments and saves on the installed 5v.pl site.
- 1.1.0 stable publication additionally requires the dedicated endpoint gate,
  real-client observations and p95 budgets; general Validate success is not
  release certification.

[Readiness details](V110_RELEASE.md) · [Real-client verification](CLIENT_COMPATIBILITY.md)
