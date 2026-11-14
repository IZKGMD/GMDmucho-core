# MuchoCore 5v.pl update helper

`MuchoCore-Update-5V-FINAL.ps1` is the self-contained Windows PowerShell 5.1/7 entry point. Its embedded Python helper is generated from `updater.py`; keep the two copies identical when editing. The script is locked to `muchocoretest.5v.pl`.

Run from PowerShell:

```powershell
& 'C:\Users\Bruh\Downloads\MuchoCore-Update-5V-FINAL.ps1' -AllowPlainFtp
```

The 5v.pl FTP server currently rejects `AUTH TLS`. `-AllowPlainFtp` permits fallback only for an explicit 500/502/504 response to `AUTH TLS`. FTP credentials and file contents travel without encryption; use it only after an explicit risk decision. TLS certificate validation for HTTPS remains enabled. The script prompts for FTP password and owner password locally with hidden input. Before the owner-only core migration, type `INSTALL` at its confirmation prompt.

A local archive requires `-ArchivePath` and its independently verified `-ExpectedArchiveSha256`. The default path selects a successful PHP7 workflow run whose commit exactly matches current `main`, then downloads that run's artifact.

The updater stores changed code and the original root `.htaccess` in `MuchoCore-Backups`, with per-file SHA-256 and a list of newly added files. It leaves the database, tenant configuration, cloud saves, signing keys, clients, uploads, and existing backups alone. The server's owner-only migration creates its own database backup before schema or data changes.

If file transfer fails, the updater restores touched files and checks the restored hashes. To restore a code backup later, after reviewing any migration state:

```powershell
& 'C:\Users\Bruh\Downloads\MuchoCore-Update-5V-FINAL.ps1' -RestoreBackup 'C:\Users\Bruh\Downloads\MuchoCore-Backups\BACKUP.zip' -AllowPlainFtp
```

A code rollback does not roll back database migrations. If migration has begun, inspect the admin Server page and its database backup before restoring old code.

Run the local FTP/HTTPS simulators:

```powershell
python tools/release/5v-updater/test_updater.py -v
```
