# First-run administrator registration

On a new interactive VPS installation, the installer prints a private one-time link. Open it and choose the owner username and password in the browser. The page closes permanently after the owner is created. Shared hosting and unattended VPS installs continue to create the owner during installation.

## Language

English is the default. Use the **EN / RU** switcher to change languages. All form fields, errors and activation notices are localized. The preference remains selected on reload.

## New VPS installation

1. Run the installer and wait for `MuchoCore is installed`.
2. Open the **private setup link** printed at the end. The link contains a secret in its browser fragment, which is removed from the address bar before the form opens. Do not share it.
3. Enter the administrator username, a password of 12–256 characters, and confirmation. You are signed into `/admin/` immediately.
4. If you close the terminal before registering, run `sudo mucho owner-link` on the server to show the link again. After registration, the command stops working.

For unattended fresh deployments, supply `MUCHO_ADMIN_USER` and `MUCHO_ADMIN_PASSWORD` through a **private environment**, not through command-line arguments or public automation logs. The installer creates this owner after migrations and does not print a setup link. If the installer is restarted before completing initial owner registration, it resumes the pending enrollment without changing the selected credentials. Updates and reinstallations never overwrite existing owners.

## New shared-hosting installation (PHP 8.2+)

1. Upload the official shared-hosting release and open `/shared-install.php`.
2. Enter MySQL details, your GDPS name, **administrator username, password and confirmation**.
3. The installer creates the owner immediately after applying migrations. Open `/admin/` and **sign in with that same password**. No extra activation or registration screen is needed.

The installer does not reset existing administrators when updating an already initialized database.

## 5v.pl / native PHP 7

The recommended 5v.pl package installer already collects the administrator credentials in its one-time setup. The native browser entry `/mc/admin.php` can also initialize a new empty database and create its first owner in one form; the database password is used only to verify installation ownership.

For the optional legacy `php7-setup.php` wizard, entering the private installer key, MySQL details and admin credentials on **one page** creates both the schema and owner together. After success, sign in through `/mc/admin.php` (or `/public/php7-admin.php` in an unpacked source checkout). Never initialize against a nonempty or unrelated database.

The PHP 7 runtime is a preview and does **not** have full PHP 8 feature parity.

## Upgrades and recovery

Existing administrators and login passwords are preserved. A database seal (`admin_initialization`) prevents reopening enrollment if accounts are later deleted. Routine deployment never resets the owner password.

If you lose access, use configured verified-email recovery. For a single active owner without two-factor authentication, `sudo mucho owner-recovery-link` issues a private, one-time password setup link valid for 24 hours. This immediately invalidates the old password and sessions. The `bin/mucho-admin-setup.sh` helper remains available for an explicit local server-console reset.

## Security boundaries

- An interactive VPS install generates a cryptographically random activation secret. Only possession of its private link authorizes the first-owner form; anonymous HTTP requests cannot claim the owner.
- Unattended VPS installs create the first owner through a CLI-only, one-time database operation.
- Existing browser-activation flows also reject anonymous registration without their private activation credential.
- The private activation credential is validated against a server-side password hash by a CSRF-protected POST, not by a URL query parameter.
- The credential arrives as a browser-only URL fragment and is removed from history before submission. The registration form uses an authenticated, short-lived session (15 minutes) instead of another password field.
- HTTPS is required except localhost development.
- Invalid activation attempts are rate-limited.
- A database advisory lock plus permanent completion marker prevent concurrent or repeat first-owner enrollment.
- Never share the private activation URL. It stops working after a successful first-owner registration.
