# First-run administrator registration

The first visit to `https://your-gdps.example/admin/` now displays **Create first administrator** instead of silently creating an owner account.

## New VPS installation

1. Install MuchoCore as usual. At the terminal, choose the **admin setup secret**. This is an authorization code for the browser wizard, *not* the final admin login password.
2. Open `https://your-gdps.example/admin/`.
3. Choose your administrator username and a new password (12–256 characters), confirm it, and enter the installer secret.
4. Submit. The new owner account is stored using PHP `password_hash`; you are signed in and redirected to the dashboard.
5. Subsequent visits show the usual sign-in page. Creating a second first owner is disabled.

The installer secret remains in `.secrets/admin_password`, readable only locally by the operator, and its hash is exposed to the admin process as `/etc/muchocore-admin.php`. The browser does not display the secret or put it in the URL.

## New shared-hosting installation

The shared installer asks for a **setup secret**. It stores a hash in the private `storage/admin-bootstrap.php` file but does **not** pre-create the administrator account.

After installation, open `/admin/` and register your first owner using the setup secret. Do not delete `storage/admin-bootstrap.php`.

## Upgrades and recovery

Existing `admin_users` records are preserved. If an admin already exists, the one-time wizard is never shown. A persisted database marker (`admin_initialization`) keeps this restriction even if all admin accounts are subsequently deleted.

Routine installs, updates and deploys no longer reset admin passwords from the Docker secret. If you must deliberately reset the Docker-installed owner from the local server console, run:

```sh
cd /opt/mucho-core
sudo bin/mucho-admin-setup.sh
```

That utility asks for a new password and invokes `bin/mucho-sync-admin.php --reset-password`. An operator can also run `sudo mucho` and choose its password reset command. This **explicit** console recovery seals the wizard as completed.

## Security boundaries

- Creating the first owner requires the secret supplied to the installer and a CSRF-protected form.
- The wizard is available over HTTPS (localhost is permitted for local development).
- A database advisory lock guarantees only one owner can be claimed during concurrent setup attempts.
- The setup secret is checked with `password_verify` and unsuccessful guesses are rate-limited.
- No registration route is available after first setup. A subsequent loss of all admins needs server-console recovery, not public sign-up.
- Do not share your setup secret or use this procedure to add normal GDPS player accounts.
