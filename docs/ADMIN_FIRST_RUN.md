# First-run administrator registration

On a new VPS installation, the first owner is created in the private SSH installer before the admin website opens. No Setup Secret or activation link is needed. Older unfinished installations and the PHP 8 direct shared-hosting installer still support the protected browser activation flow.

## Language

English is the default. Use the **EN / RU** switcher to change languages. All form fields, errors and activation notices are localized. The preference remains selected on reload.

## New VPS installation

1. During the interactive SSH installation, enter an administrator username (default `admin`).
2. Enter your administrator password (12–256 characters) and confirm it. Input is hidden in the terminal and never placed on the command line or in GitHub logs.
3. After database migrations, the installer registers exactly one owner and seals the one-time setup.
4. Open `https://your-gdps.example/admin/` and **sign in immediately** with those credentials. No browser registration or separate activation link is necessary.

For unattended fresh deployments, supply `MUCHO_ADMIN_USER` and `MUCHO_ADMIN_PASSWORD` through a **private environment**, not through command-line arguments or public automation logs. If the installer is restarted before completing initial owner registration, it resumes the pending enrollment without changing the selected credentials. Updates and reinstallations never overwrite existing owners.

## New shared-hosting installation

The direct shared-hosting installer displays a **private first-owner activation link** on the successful installation page. Follow it and choose the owner username/password; no Setup Secret field is shown on the registration form. Keep `storage/admin-bootstrap.php` private. Automated shared-hosting flows must deliver an equivalent private activation handoff to the installer operator.

## Upgrades and recovery

Existing administrators and login passwords are preserved. A database seal (`admin_initialization`) prevents reopening enrollment if accounts are later deleted. Routine deployment never resets the owner password.

If you lose access, use configured verified-email recovery or an explicit local server-console reset. The `bin/mucho-admin-setup.sh` helper is a reset tool on a running installation; it should not be invoked casually.

## Security boundaries

- A VPS install creates the first owner through a CLI-only, one-time database operation. Anonymous HTTP requests cannot claim that owner.
- Existing browser-activation flows also reject anonymous registration without their private activation credential.
- The private activation credential is validated against a server-side password hash by a CSRF-protected POST, not by a URL query parameter.
- The credential arrives as a browser-only URL fragment and is removed from history before submission. The registration form uses an authenticated, short-lived session (15 minutes) instead of another password field.
- HTTPS is required except localhost development.
- Invalid activation attempts are rate-limited.
- A database advisory lock plus permanent completion marker prevent concurrent or repeat first-owner enrollment.
- Never share the private activation URL. It stops working after a successful first-owner registration.
