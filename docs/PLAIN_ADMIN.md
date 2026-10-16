# Plain Admin

Open `/admin/plain/` for the second administration interface. The **Plain admin
(no CSS)** link is available on the regular login and administration screens.
The **Styled admin** link switches back. Both interfaces can stay open in separate
tabs and use the same administrator session.

Plain Admin serves ordinary HTML, native controls, expandable records and tables.
It removes stylesheets and inline styles from shared modules and enforces
`style-src 'none'`. Functional JavaScript remains for passkeys, confirmation
dialogs and tools that require it. Players, levels, music and clans use ordinary
GET/POST forms; their basic workflows do not require JavaScript.

The entrypoint delegates to the existing authenticated admin controller. Passwords,
2FA/recovery codes, permission checks, CSRF tokens, audit records and download
authorization are shared. Search, pagination and POST redirects preserve the
selected interface. JSON and downloadable files bypass HTML processing.

## Clans

The **Clans** page is available in both interfaces to administrators with
`social.manage`. No additional permission or database migration is required.
Search by name, tag, owner name or clan ID; filter open/invite-only clans and open
a clan to edit its details, membership limit and joining policy. Member roles,
removal, ownership transfer and deletion use the existing transactional clan
repository, including quest settlement and owner protection. The admin audit log
and clan activity messages identify the administrator who performed the action.

Ownership transfer and permanent deletion also require the current clan tag to
be typed into the form, including when JavaScript is disabled.

## Verification

Run `php tests/application/admin-plain-integration.php` for HTML/URL preservation
and the permission mapping, together with the existing admin integration tests.
