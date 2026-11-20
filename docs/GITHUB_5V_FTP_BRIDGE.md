# GitHub → 5v.pl FTP bridge (MuchoCore test site only)

The repository's manual workflow `.github/workflows/5v-ftp-bridge.yml`
updates **only** `muchocoretest.5v.pl`. It is not a general remote shell or
arbitrary FTP command bridge. GitHub Actions runs the existing, tested
`tools/release/5v-updater/updater.py` in headless mode and uses its HTTPS
origin verification, file read-back verification, backup gate and rollback
after a failed upload.

## One-time setup (done by the hosting account owner, not by ChatGPT)

In [GitHub repository Settings → Environments](https://github.com/IZKGMD/GMDmucho-core/settings/environments),
create the environment **`muchocore-5v-test`**, restrict deployments to
the `main` branch, and optionally require reviewer approval. GitHub's
`workflow_dispatch` deployment also checks the repository, branch and
actor `IZKGMD`.

Under the **environment's secrets**, add:

| Secret | Required for | Content |
| --- | --- | --- |
| `MUCHO_5V_FTP_USER` | upload/deploy | FTP login from 5v.pl |
| `MUCHO_5V_FTP_PASSWORD` | upload/deploy | FTP password |
| `MUCHO_5V_BACKUP_KEY` | upload/deploy | Random **32+ character** backup encryption passphrase |
| `MUCHO_5V_OWNER_USER` | deploy only | Existing administrator owner username |
| `MUCHO_5V_OWNER_PASSWORD` | deploy only | Existing administrator owner password |
| `MUCHO_5V_OWNER_OTP` | deploy with 2FA | Current single-use 2FA/recovery code. Do not store a TOTP code long term. |

Optional **environment variables**: `MUCHO_5V_FTP_HOST` (FTP hostname
specified by your hosting provider, must end in `.5v.pl` or be `5v.pl`)
and `MUCHO_5V_FTP_ROOT` (FTP website root). The updater verifies the
HTTPS site ownership before upload. No credentials, tokens or passwords
belong in the workflow inputs, chat, issue comments, source files or PRs.

Generate the backup key locally with a password manager or
`python -c "import secrets; print(secrets.token_urlsafe(48))"`.
Store it outside the repository. Without this exact key you will not be
able to decrypt a code backup.

## Running

Open [Actions → MuchoCore 5v.pl FTP bridge](https://github.com/IZKGMD/GMDmucho-core/actions/workflows/5v-ftp-bridge.yml)
and choose **Run workflow**, branch **main**.

- **check**: inspect the most recent successful PHP7 archive built from
  the exact same SHA as `main`, without connecting to FTP.
- **upload**: create a backup and upload verified code. Afterwards log in
  to `https://muchocoretest.5v.pl/mc/admin.php?page=system` yourself
  and choose **Install uploaded build** to run migrations.
- **deploy**: also log in as the *existing owner* and perform the
  owner-confirmed migration. Requires owner secrets and the workflow input
  `confirmation=INSTALL`. A 2FA code may expire before the runner uses it;
  in that case choose upload and activate in the browser.

`ftp_policy=ftps-only` is the safe default. If 5v.pl responds with
`500/502/504` to `AUTH TLS`, the updater **stops**. Choosing
`allow-plain-ftp` for that run explicitly permits fallback to cleartext
FTP **only when the host rejected AUTH TLS**. This exposes FTP credentials
and uploaded PHP content on the network, even though GitHub keeps Secrets
encrypted at rest. This is a provider limitation; do not confuse
GitHub Secrets with encrypted transport. Prefer FTPS or SFTP if the host
offers it. Consider rotating the FTP password after any cleartext run.

The workflow only uses PHP7 packages from a successful
`php7-preview-archives.yml` workflow matching the exact main commit;
a newer source commit must have its own successful build. The bridge
never downloads arbitrary URLs and never accepts arbitrary commands.

## Backups and recovery

Before changing files, the updater creates a local ZIP of replaced code.
GitHub Action **encrypts it using OpenPGP/AES-256** and attaches only
`*.zip.gpg` as an Actions artifact (30-day retention).
The original ZIP never appears as an artifact. For an upload that fails
before backup creation, no backup artifact is produced and no changed
files should have been uploaded.

To restore later, download the encrypted artifact on your own PC,
decrypt it locally with GPG (it will prompt for the backup passphrase),
inspect the contents and restore via the existing `-RestoreBackup`
option of the PowerShell updater. Do not upload the decrypted ZIP to GitHub.

**A code rollback does not reverse database migrations.** If activation
already started, inspect the server's own database backup and migration
checkpoint rather than reverting code blindly.

The new 96-character emergency recovery code is created in
`mc/config/php7-emergency-code.php` by the live 5v.pl instance, not
included in any release or GitHub workflow output.

## Security boundaries

- No issue-comment FTP command dispatcher, arbitrary host input, or
  user-editable ZIP source.
- Only `workflow_dispatch` from trusted `main` by `IZKGMD`.
- GitHub Actions can use secrets placed in its protected environment:
  anyone who can modify trusted workflow/code on `main` could potentially
  alter how those secrets are handled. Protect branch access and review PRs.
- A successful action validates HTTPS probes and file upload, **not**
  actual inbox delivery or every GD 2.209 game-client endpoint.
- A 5v.pl FTP login has broad file access. Rotate the password and revoke
  GitHub secrets if you no longer use the bridge.
