# Administrator email recovery (PHP 8)

This feature is separate from player recovery and TOTP recovery codes.

## Setup

The owner sets SMTP values in the private .env of the active PHP 8 install.
Never share the SMTP password in a public GitHub issue or a VPS-bridge command.

    MUCHO_ADMIN_MAIL_ORIGIN=https://gdps.example.com
    MUCHO_ADMIN_SMTP_HOST=smtp.example.com
    MUCHO_ADMIN_SMTP_PORT=587
    MUCHO_ADMIN_SMTP_SECURITY=starttls
    MUCHO_ADMIN_SMTP_USER=mailer@example.com
    MUCHO_ADMIN_SMTP_PASSWORD=REPLACE_PRIVATELY
    MUCHO_ADMIN_MAIL_FROM=mailer@example.com

Use port 465 and SECURITY=tls for implicit TLS. TLS verification and
SMTP AUTH are mandatory. There is no fallback to insecure SMTP or PHP mail().
Configure the sending domain through SPF, DKIM and DMARC.

The origin must be the exact public HTTPS domain serving /admin/,
without a trailing slash. Host headers are never used to compose links.
Ensure the container runtime can read the private environment.

## Operation

1. A logged-in administrator opens Admin → Administrators → Recovery email.
2. Enter an email address, current password, and TOTP/recovery code if enabled.
3. Click the email verification link (expires after 30 minutes) and confirm.
4. On the login page, use Forgot your password? and enter the verified email.
5. Reset the password with the single-use link (20 minutes, 12–72 bytes).

The email stays unverified until confirmed. Tokens have 256 random bits,
only SHA-256 token digests are stored, and reset consumption is transactional.
Public reset requests never reveal if an address is registered. IP and email
rate limits and a ten-minute per-account resend cooldown apply.

After reset, old authenticated sessions are invalidated on their next request.
TOTP remains enabled and is still required on password sign-in.
Existing admin sessions from before this update require a fresh login.

## Verification before claiming email delivery

Check GET /admin/?email_recovery=1, request a message to a test mailbox,
confirm the received email, test that reset links are single-use, ensure
existing sessions become invalid, and check that TOTP still applies.

HTTP 200 and passing CI are not evidence that SMTP mail was delivered.
If SMTP is missing, the interface explains this and console reset remains
the fallback. The PHP 7 and Go editions are not part of this change.
