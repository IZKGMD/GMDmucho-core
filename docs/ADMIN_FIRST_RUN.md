# First-run administrator registration

On a **fresh interactive VPS installation**, the first screen collects
the owner login and password (plus confirmation) in the private server terminal.
After installation, sign in normally at `https://YOUR-DOMAIN/admin/`.
There is no extra setup key or browser activation link to copy.

A private bootstrap password is used internally only during initial deployment
and is rotated after the owner is securely created. Existing administrators
and interrupted legacy browser setup flows remain protected.

## Language

The admin panel supports English and Russian. The original first-run
browser activation UI remains available only to already-pending legacy
installations, not as the default fresh VPS flow.

## New VPS installation

1. Start the MuchoCore installer in your VPS terminal.
2. **Step 1:** enter the administrator username, password (12–256 bytes),
   and confirm the password. No extra key is requested.
3. Enter the GDPS name and domain. The installer configures Docker, MariaDB,
   HTTPS, applies migrations and creates the first owner automatically.
4. Open `https://YOUR-DOMAIN/admin/` and sign in. No second registration.

The first owner is created by the server-side CLI after migrations, not by an
anonymous first web visitor. Existing installations do not offer a second
first-owner flow, and repeat installs preserve the chosen login/password.

For unattended installs use `MUCHO_ADMIN_USER` and `MUCHO_ADMIN_PASSWORD`
through a **private environment** (not public logs, command arguments or
query strings). If an old installation was interrupted before the browser
setup was completed, `sudo mucho admin` can inspect the state and provide
the **legacy** private setup link in a private terminal. New installations do
not need that link.

Use `sudo mucho doctor` to check container health, DNS, public HTTPS,
database migrations and whether an active owner exists. Do not post
administrator passwords or private recovery links to public GitHub issues.

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

- A **new interactive VPS install** collects the owner credentials in a private terminal and creates the owner through the CLI after database migrations; anonymous HTTP requests cannot claim the owner.
- Unattended VPS installs create the first owner through a CLI-only, one-time database operation.
- Existing browser-activation flows also reject anonymous registration without their private activation credential.
- The private activation credential is validated against a server-side password hash by a CSRF-protected POST, not by a URL query parameter.
- The credential arrives as a browser-only URL fragment and is removed from history before submission. The registration form uses an authenticated, short-lived session (15 minutes) instead of another password field.
- HTTPS is required except localhost development.
- Invalid activation attempts are rate-limited.
- A database advisory lock plus permanent completion marker prevent concurrent or repeat first-owner enrollment.
- Never share the private activation URL. It stops working after a successful first-owner registration.
