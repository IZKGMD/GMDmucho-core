# MuchoCore PHP runtime compatibility

MuchoCore is one shared game-server core. The Geometry Dash protocol version
and the hosting PHP interpreter version are **different** compatibility axes.

| PHP runtime | Deployment | Notes |
| --- | --- | --- |
| 8.5 / 8.4 / 8.3 | Native (shared host or VPS) | Recommended. The official Docker image remains on PHP 8.3. |
| 8.2 | Native shared hosting | Composer, installer, parser, security and GD protocol tests run under PHP 8.2 CI. Prefer upgrading to 8.3+ before 8.2 reaches its security-support end. |
| 8.1 / 8.0 | Not officially supported | Out of security support. Do not bypass the version gate. |
| 7.x, including 5v.pl's advertised 7.0.19 | **Not a native MuchoCore runtime** | Old language syntax, no supported passkeys PHP dependency, unsupported security runtime. |

## Shared-hosting users

Use the current shared-hosting ZIP build with **PHP 8.2 or newer**,
`pdo_mysql`, `openssl`, `mbstring`, sessions, writable storage, and a
supported MySQL/MariaDB. The PHP/DB checks in the browser bootstrap must
succeed before installation. PHP 8.3+ is preferable for longer-term support.

The CI version matrix covers Composer lock validation, source parsing, core
protocol and security smoke tests on PHP 8.2 through 8.5. Separate MariaDB
integration jobs verify real migrations and GD 2.209 server data flows on PHP
8.2 and 8.3. This is not a guarantee of every individual shared host's disabled
functions, request limits or native client behavior; check the host first.

## Special case: 5v.pl

5v.pl currently advertises PHP 7.0.19. The normal MuchoCore package cannot
execute there, even after changing `composer.json`. A native PHP7 rewrite
would need a completely separate core and authentication/security review, and
would still run on a runtime without ongoing security fixes. We will **not**
pretend that such a rewrite is safe or complete.

An opt-in PHP 7 compatible **HTTP edge/relay** is provided at
`tools/hosting/5v-edge.php`. It can relay a limited set of GD POST/GET paths
to a different server you operate with PHP 8.2+ and a database. It does not
move the server's business logic or DB to 5v, and live 5v network behavior
and permissions still must be verified.

See [5v experimental hosting](HOSTING_5V_EDGE.md) and
[Shared Hosting](SHARED_HOSTING.md).

## Release policy

Changing a Composer minimum and passing CI does not certify GD 2.209 native
client acceptance. The MuchoCore 1.1.0 stable release remains subject to
its endpoint and real-client release gates.
