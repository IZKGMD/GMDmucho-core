# Cvolton Migration

MuchoCore includes a destination-side migration wizard for Cvolton/GMDprivateServer.

## Supported source data

The current database-to-database importer transfers:

- accounts → MuchoCore accounts
- users → MuchoCore profiles
- levels → MuchoCore levels
- levelscores → regular level scores
- platscores → platformer scores

Other Cvolton tables are intentionally left untouched until a dedicated field mapping is added.

## Safety

The source database connection is opened read-only at the MariaDB session level. The importer uses SELECT queries only; it does not execute the Cvolton PHP application or source-side shell commands.

Credential handling is conservative:

- recognized PHP password hashes are copied as hashes;
- a Cvolton 40-character GJP2 credential is re-hashed before storage;
- plaintext or unknown password values are never stored;
- such accounts receive a random unusable password hash and are recorded as needing account recovery;
- Cvolton administrator flags are never promoted to MuchoCore admin or owner privileges.

Source account IDs and level IDs are kept in persistent mapping tables so repeated imports remain deterministic.

## Dry-run

Run the migration status first:

    cd /opt/mucho-core
    php bin/migrate.php migrate

Then inspect the source:

    php bin/import-cvolton-db.php       --source-host=127.0.0.1       --source-db=geometrydash       --source-user=root

Dry-run is the default and never changes the destination.

## Apply

Back up the destination database first. Then:

    php bin/import-cvolton-db.php       --source-host=127.0.0.1       --source-db=geometrydash       --source-user=root       --apply       --confirm=COVOLTON

Avoid putting passwords into shell history. Use CVOLTON_SOURCE_PASS instead:

    export CVOLTON_SOURCE_PASS='your-source-db-password'

The target-side import runs inside one transaction and rolls back on failure.

## Verification

    php bin/migrate.php status
    curl -fsS https://your-domain.example/health

Then test representative migrated accounts, levels and scores in the matching Geometry Dash client.

## Current limitations

Comments, comment likes, private messages, friendships, gauntlets, map packs, lists, rewards and source-specific moderation data are not migrated yet. This is intentional so no ambiguous source field is silently mapped to the wrong target field.
