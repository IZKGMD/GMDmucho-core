# First-run administrator registration

On new installations, the first administrator is created **during setup**, not on a second visit. No separate Setup Secret or activation link is needed on VPS, PHP 8 shared hosting or the manual PHP 7 installer. Existing unfinished installations retain their protected recovery/activation routes.

## Language

English is the default. Use the **EN / RU** switcher to change languages. All form fields, errors and activation notices are localized. The preference remains selected on reload.

## New VPS installation

1. During the interactive SSH installation, enter an administrator username (default `admin`).
2. Enter your administrator password (12–256 characters) and confirm it. Input is hidden in the terminal and never placed on the command line or in GitHub logs.
3. After database migrations, the installer registers exactly one owner and seals the one-time setup.
4. Open `https://your-gdps.example/admin/` and **sign in immediately** with those credentials. No browser registration or separate activation link is necessary.

For unattended fresh deployments, supply `MUCHO_ADMIN_USER` and `MUCHO_ADMIN_PASSWORD` through a **private environment**, not through command-line arguments or public automation logs. If the installer is restarted before completing initial owner registration, it resumes the pending enrollment without changing the selected credentials. Updates and reinstallations never overwrite existing owners.

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
