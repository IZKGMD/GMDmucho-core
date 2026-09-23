# MuchoCore — account recovery

The recovery page is available at:

```text
https://YOUR-DOMAIN/api/api/accounts/lostusername.php
```

The page supports two flows:

1. Request a reset link with a username or email.
2. Open the one-time token from email and set a new password.

The Geometry Dash-compatible account API remains separate from this web flow.

## 1. Run the database migration

From the project root:

```bash
php bin/migrate.php
```

This creates:

```text
account_recovery_tokens
```

If your installation already runs migrations during deploy, no separate SQL import is required.

## 2. Configure the public URL

Add these values to `.env`:

```dotenv
MUCHO_PUBLIC_URL=https://your-domain.example
MUCHO_RECOVERY_FROM=no-reply@your-domain.example
```

Use the real HTTPS origin of the GDPS for `MUCHO_PUBLIC_URL`.

## 3. Mail delivery

The recovery module uses PHP's built-in `mail()` function, so the hosting environment must provide a working mail transport.

For production, make sure:

- the sender address belongs to your domain;
- the domain has working DNS/mail configuration;
- the server is allowed to send mail.

When `mail()` cannot send, the module writes an error to the PHP error log but does not reveal whether an account exists.

## 4. Test

Open:

```text
https://your-domain.example/api/api/accounts/lostusername.php
```

Enter a real account username or email and submit the form.

Then:

1. check the mailbox;
2. open the recovery link;
3. enter a new password of at least 8 characters;
4. log into Geometry Dash with the new password.

## Security properties

Recovery tokens are random 32-byte values. Only the SHA-256 hash is stored in the database.

Tokens expire after 30 minutes and are single-use.

Requests are rate-limited by IP and by normalized identity.

The request page uses a generic success message so the endpoint does not reveal whether a username/email exists.

Password changes update both `password_hash` and the Geometry Dash-compatible `gjp2_hash`.
