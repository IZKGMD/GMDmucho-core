# Geometry Dash 2.209 — isolated live-client acceptance test

Status: **prepared only**. Staging creation requires the VPS operator.
The code in PR #123 has not been deployed to a live server just because
GitHub Actions passed. Never copy production DB volumes into this stage.

## Staging layout

- Separate Git checkout: `/opt/mucho-gd2209-staging` at the exact PR head.
- Separate Docker Compose project: `muchocore-gd2209-staging`.
- Separate MariaDB and runtime volumes using existing
  `docker-compose.test.yml` and stage overlay.
- Three services only: `testgdps-db`, `testgdps-app`, `gd2209-stage-web`.
- Loopback-only HTTP: `http://127.0.0.1:18080` on the VPS.
- No worker and no ports 80/443. Production Caddy/app/worker unchanged.
- Credentials are freshly generated and stored at
  `/opt/mucho-gd2209-staging/.secrets/testgdps_*`, mode 0600.
- A separate database is migrated on startup, including test-only 2.209
  report-queue changes. No destructive rollback or production schema upgrade.
- Stage tracing is enabled for safe request metadata (not payloads); disable
  before opening the stage publicly to untrusted traffic.

## First-time VPS launch

From an SSH shell on the VPS, with production running:

```bash
curl -fL --retry 3 \
  https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/fix/gd2209-compat-trace-20261017/bin/mucho-gd2209-staging.sh \
  -o /tmp/mucho-gd2209-staging.sh
bash -n /tmp/mucho-gd2209-staging.sh
sudo bash /tmp/mucho-gd2209-staging.sh start
```

First run builds the stage PHP image and may take several minutes. The command
checks health and that unauthenticated `adminGetReportQueue` is rejected.
No password or other secret should be posted to ChatGPT.

```bash
sudo bash /tmp/mucho-gd2209-staging.sh status
sudo bash /tmp/mucho-gd2209-staging.sh logs
sudo bash /tmp/mucho-gd2209-staging.sh smoke
```

To stop the stage **without deleting its test database**:

```bash
sudo bash /tmp/mucho-gd2209-staging.sh stop
```

Do not run `docker compose down -v`; that would destroy staging test data.

## Real Windows client: HTTPS required

The **original verified GD 2.209 EXE patcher refuses HTTP and explicit
ports**; its target must be a short HTTPS hostname.

For the original client, `t.muchogdps.space` is a candidate: it is 17 bytes,
the same as `www.boomlings.com` and shorter than
`www.geometrydash.com`. The original 2.209 EXE contains one
malformed, one-byte-shorter `/databas/checkIfServerOnline.php` URL.
The staging patcher keeps its spelling and the staging Caddyfile internally
rewrites it to `/database/checkIfServerOnline.php`. This preserves PE size.
This is a test hostname **proposal**, not a claim
that DNS or TLS has already been configured.

A practical secure ingress is a **separate Cloudflare Tunnel** with hostname
`t.muchogdps.space`, connected to `http://127.0.0.1:18080`
on the VPS. It does not require touching the production Caddy ports or
production Compose project. It does require DNS/tunnel authorization by the
domain owner, and it must be accessible to your patched game over HTTPS.

Never point the test client at the live `muchogdps.space` domain and never
patch over the original EXE. Use a fresh private test account and a separate
test copy of the client.

A newly registered test account begins as a normal user. To open the native
Moderation Queue for real testing, the operator must promote an explicitly
chosen **staging-only test account**, never a production account, and confirm
the role from the isolated test DB. Do not grant the elevated role via
untrusted HTTP input or bypass the service's RBAC checks.

## What to capture

1. On stage, verify health, registration, login and level searches.
2. As an authorized test moderator, open the Moderation Queue with **zero**
   reports. The native response should be `0:0:20#`. Watch for
   `Failed to load the queue`, not merely the HTTP status.
3. Using separate test accounts, create at least one genuine content report
   (once the `reportGJContent` request mapping has been implemented) and
   verify nonempty queue serialization.
4. Inspect the **sanitized** trace:
   ```bash
   cd /opt/mucho-gd2209-staging
   docker compose -p muchocore-gd2209-staging \
     -f docker-compose.test.yml -f docker-compose.gd2209-stage.yml \
     exec -T testgdps-app \
     php bin/mucho-gd2209-trace-report.php --min-binary=51 --json \
     storage/testgdps-client-trace.ndjson
   ```
   If it contains an unexpected account ID or secret, stop and redact.
5. Compare observed requests to `docs/GD_2209_NATIVE_EXE_FINDINGS.md`
   and the remaining 52-route audit. Treat a registered route as provisional
   until the actual client accepts it.

## Important release gate

Passing Docker health, CI tests or API probes is **not** native-client
certification. A stable 2.209 release must verify actual Windows and Android
screens and include the missing administrative, reporting and audio endpoints.

If stage setup fails, send only the last few lines of
`sudo bash /tmp/mucho-gd2209-staging.sh logs`; remove IPs, credentials,
access tokens, report bodies and account identifiers first.
