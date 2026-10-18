# SpaceWeb Free and other shared-hosting GDPS deployments

**Status (2026-10-18): experimental / not yet supported on SpaceWeb Free.**
This is a compatibility checklist, not a claim that Geometry Dash connects.

## SpaceWeb's documented free tier

SpaceWeb's provider-specific [free-tier technical sheet](https://help.sweb.ru/harakteristiki-tarifa-besplatnyj_1475.html) (updated July 2, 2026) lists:

- 1 GB disk, one site, one database, one FTP account and SSH;
- selectable PHP 8.3+;
- **MySQL 5.7** (the generic marketing page also mentions MySQL 8; verify the real server using `SELECT VERSION()`);
- a technical subdomain `login.temp.swtest.ru` and Let's Encrypt subject to provider verification;
- a total CPU allowance of 15 processor minutes per day and MySQL limits of 200 processor seconds per day / 15 per 15 real minutes;
- no script-sent email, no extra/dedicated IP and no support on this tariff.

Current MuchoCore releases **require MySQL 8.0.29+ or MariaDB 10.4+**.
Do **not** change the minimum version check to 5.7: some leaderboard paths
use MySQL 8 window functions and CTEs, and migrations have not been verified
on MySQL 5.7. A free account with MySQL 5.7 is currently blocked by the
installer for a good reason. If the provider supplies MariaDB 10.4+ or MySQL
8.0.29+ on the *actual account*, proceed with compatibility testing.

## Step 1: check PHP and non-browser POST access before installing

1. Download `tools/hosting/muchocore-probe.php` from this repository.
2. Upload it **temporarily** to the site's public document root as
   `muchocore-probe.php` (no other MuchoCore files needed).
3. Open `https://YOUR-HOST/muchocore-probe.php` in the browser and inspect
   the PHP readiness report.
4. From a *different network/device*, run:

   ```sh
   curl -sS -i -A 'GeometryDash' \
     -H 'Content-Type: application/x-www-form-urlencoded' \
     --data 'probe=muchocore' \
     'https://YOUR-HOST/muchocore-probe.php?mode=gd'
   ```

   Expected: HTTP 200, `Content-Type: text/plain`, response body **exactly**
   `1`. If you receive browser verification HTML, 403, redirects to login
   pages, or JavaScript challenges, *do not deploy*: the game cannot satisfy
   that web browser challenge. Do not bypass provider security restrictions.

5. **Delete `muchocore-probe.php` immediately after testing.** It reveals
   environment details and is not a permanent production endpoint.

Passing this probe is **necessary but not sufficient**: it does not check the
actual patched game's hostname, certificates, redirects, WAF rules, or every
GDPS endpoint. Confirm gameplay by sending registration, login, cloud-save and
level upload/download requests from a real Windows/Android game client.

## Step 2: check the actual database version (without disclosing credentials)

In the provider's phpMyAdmin SQL console (or MySQL console if permitted), run:

```sql
SELECT VERSION();
```

- MySQL 8.0.29+ or MariaDB 10.4+: the version meets current requirements.
- MySQL 5.7: **not yet compatible**; see the compatibility task above.
- Anything else: do not guess. Use the shared installer preflight.

Never upload database credentials to the temporary public probe.

## Step 3: deploy to a compatible shared-hosting account

Use the [no-Docker shared-hosting guide](SHARED_HOSTING.md). Upload the
pre-packaged shared-hosting release via FTP or use the
[official FTP deployer](https://muchogdps.space/install/shared/).

Use a domain with valid HTTPS. A **dedicated IP address is not required**
for normal name-based HTTPS hosting; the provider must accept direct API
requests from desktop/mobile applications.

Verify `/health` returns `1`, then test *real* legacy API URLs such as
`/database/getGJLevels21.php` from the patched game.

## Performance and service limits

SpaceWeb Free is useful for experimentation, not a guaranteed busy public
GDPS. Stay within the provider's resource quota rather than attempting to
circumvent it:

- Use MySQL-backed cache when available (`MUCHO_CACHE_DRIVER=database`).
- Keep large media off the application database if the host/storage quota
  would be exhausted. Delete unused uploads.
- Do not run a permanent worker process on a free shared-hosting account;
  features requiring asynchronous workers may need to be disabled or moved.
- Keep backups off-host. Back up the cloud-save key **together with** the
  database. Do not place backups or .env under the public document root.
- Do not schedule continuous polls or load tests: the free-tier CPU and
  MySQL quotas are strict and violations may suspend the account.
- Password recovery emails will not work without an approved external
  mail transport because SpaceWeb Free disables sending mail from scripts.

Be transparent about any unavailable features. Confirm hosting provider
terms before allowing game clients and player data.

## Investigation checklist

- [ ] Hosting accepts the temporary form POST from outside a browser
- [ ] Hosting has PHP 8.3+, PDO MySQL, and required PHP extensions
- [ ] SQL server is at or above the supported version
- [ ] HTTPS hostname and certificate are valid
- [ ] PHP file routing reaches `/database/*.php` paths
- [ ] Game-client registration/login and level upload/download work
- [ ] Cloud Save works and its encryption key is included in backups
- [ ] Resource consumption stays within CPU, MySQL and disk limits
