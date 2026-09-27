# MuchoCore Migration Kit

The Migration Kit is the operator-friendly entry point for moving a Cvolton/GMDprivateServer-style GDPS database into MuchoCore.

For hosted FHGDPS installations, the public GDPS/web hostname is not automatically the database hostname. Provide the actual MariaDB/MySQL host, database name and database credentials supplied by the source installation. The kit never assumes that a public web URL is a database endpoint.

It wraps the existing destination-side importer so the normal migration flow is:

\`\`\`text
Preflight
  ↓
Review counts
  ↓
Backup target
  ↓
Apply transaction
  ↓
Healthcheck
  ↓
Test
\`\`\`

## What it migrates

The current importer maps:

| Source table | MuchoCore destination |
| --- | --- |
| \`accounts\` | Accounts |
| \`users\` | Player profiles |
| \`levels\` | Levels |
| \`levelscores\` | Regular level scores |
| \`platscores\` | Platformer scores |

Other source tables are intentionally not copied until a deterministic field mapping exists.

## Safety model

### Dry-run is the default

\`\`\`bash
sudo ./tools/migration/mucho-migrate.sh \\
  --source-host=SOURCE_DB_HOST \\
  --source-db=SOURCE_DB_NAME \\
  --source-user=SOURCE_DB_USER
\`\`\`

It verifies:

- Docker and the MuchoCore app container are running;
- the target database is healthy;
- required Cvolton mapping tables exist;
- the source contains the required \`accounts\`, \`users\` and \`levels\` tables;
- optional score tables and row counts;
- the exact source counts reported by the importer.

No destination records are changed by the dry-run.

### Apply requires an explicit confirmation

\`\`\`bash
sudo ./tools/migration/mucho-migrate.sh \\
  --source-host=SOURCE_DB_HOST \\
  --source-db=SOURCE_DB_NAME \\
  --source-user=SOURCE_DB_USER \\
  --apply --confirm=COVOLTON
\`\`\`

The kit first creates a verified destination database backup using the existing MuchoCore backup tooling. The backup must produce a valid dump, pass gzip integrity verification and have a checksum before the importer is allowed to write anything.

The low-level importer enforces the same backup requirement, so bypassing the wrapper cannot silently skip the safety checkpoint.

The source database is opened read-only by the importer. The destination import executes inside a transaction and rolls back on failure.

The importer keeps persistent source-to-target account and level mappings, so repeated imports remain deterministic.

## Passwords

Do not put the production source password directly into the command line.

Use:

\`\`\`bash
export CVOLTON_SOURCE_PASS='your-source-db-password'
sudo -E ./tools/migration/mucho-migrate.sh \\
  --source-host=SOURCE_DB_HOST \\
  --source-db=SOURCE_DB_NAME \\
  --source-user=SOURCE_DB_USER
\`\`\`

Without that environment variable, the kit prompts for the password without echoing it.

Unset the variable after the migration:

\`\`\`bash
unset CVOLTON_SOURCE_PASS
\`\`\`

## Reports

Each run writes an operator report to:

\`\`\`text
/opt/mucho-core/backups/migrations/
\`\`\`

The directory is ignored by Git. Keep the report together with the verified target backup until the cutover has been validated.

## Before switching production traffic

After a successful apply:

1. Sign in with representative migrated accounts.
2. Open representative migrated levels.
3. Verify regular and platformer scores.
4. Test comments/social flows that matter to your GDPS.
5. Test the patched client on the intended Geometry Dash generation.
6. Keep the target backup until the production cutover is complete.

## Current scope and limitations

The migration is intentionally conservative.

Not migrated yet:

- comments and comment likes;
- private messages;
- friendships;
- gauntlets;
- map packs;
- lists;
- rewards not represented by the current profile mapping;
- source-specific moderation data.

Legacy password formats that cannot be safely preserved are not converted to plaintext or guessed hashes. Such accounts are marked as requiring password recovery.

See [Cvolton Migration](CVOLTON_MIGRATION.md) for the low-level importer details.
