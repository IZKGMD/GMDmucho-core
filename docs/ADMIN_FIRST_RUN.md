# First-run administrator registration

The first owner chooses a username and password in the browser. **There is no separate Setup Secret field.** A private activation link confirms control of the new server before the registration form becomes available, protecting against first-visitor account takeover.

## Language

English is the default. Use the **EN / RU** switcher to change languages. All form fields, errors and activation notices are localized. The preference remains selected on reload.

## New VPS installation

1. Install MuchoCore normally. The installer generates a private activation credential automatically; it does **not** ask you to invent a second setup password.
2. The interactive installer prints a private activation link at the end, such as `https://gdps.example.com/admin/#setup=...`. Open that link in your browser. The browser uses the credential to authorize the session, then removes it from the URL bar.
3. Enter the owner username, choose a password (12–256 characters), confirm it, and submit. There is no extra secret field.
4. Your owner account is created and signed in. Afterward the one-time enrollment is permanently sealed.

If you missed the link, run this command in a **private SSH session** on the VPS:

```bash
cd /opt/mucho-core
sudo docker compose exec -T app php bin/mucho-owner-link.php
```

Do **not** put the command's output (the link) into a public issue, shared console log, chat message or screenshot. The URL fragment is kept out of HTTP request URLs; it is nevertheless a bearer credential until registration is completed.

Unattended deployments do not print the activation link. The operator retrieves it later through the same private SSH command.

## New shared-hosting installation

The direct shared-hosting installer displays a **private first-owner activation link** on the successful installation page. Follow it and choose the owner username/password; no Setup Secret field is shown on the registration form. Keep `storage/admin-bootstrap.php` private. Automated shared-hosting flows must deliver an equivalent private activation handoff to the installer operator.

## Upgrades and recovery

Existing administrators and login passwords are preserved. A database seal (`admin_initialization`) prevents reopening enrollment if accounts are later deleted. Routine deployment never resets the owner password.

If you lose access, use configured verified-email recovery or an explicit local server-console reset. The `bin/mucho-admin-setup.sh` helper is a reset tool on a running installation; it should not be invoked casually.

## Security boundaries

- Anonymous visitors cannot register an owner merely by reaching `/admin/`.
- The private activation credential is validated against a server-side password hash by a CSRF-protected POST, not by a URL query parameter.
- The credential arrives as a browser-only URL fragment and is removed from history before submission. The registration form uses an authenticated, short-lived session (15 minutes) instead of another password field.
- HTTPS is required except localhost development.
- Invalid activation attempts are rate-limited.
- A database advisory lock plus permanent completion marker prevent concurrent or repeat first-owner enrollment.
- Never share the private activation URL. It stops working after a successful first-owner registration.
