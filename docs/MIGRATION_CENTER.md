# Migration Center

MuchoCore includes a guided database migration flow so moving an existing GDPS does not require manually rewriting SQL.

## What the wizard does

The Migration Center follows this order:

1. connect to the old database;
2. read the old schema in read-only mode;
3. identify the database family from its tables;
4. show exactly where each kind of data lives;
5. show a preview of the data currently imported automatically;
6. wait for an explicit MIGRATE confirmation before writing to MuchoCore.

The default mode is a dry-run. A dry-run does not write the destination database.

## What to enter

From the MuchoCore VPS:

~~~text
sudo mucho
→ Database & migrations
→ Migration Center
~~~

The wizard asks for five source database values.

| Field | What it means | Where to find it |
|---|---|---|
| Old DB host | MySQL/MariaDB server address of the old GDPS | Old hosting/database panel. Do not enter the GDPS website URL. |
| Old DB port | MySQL/MariaDB TCP port | Usually 3306; use the old host's value when different. |
| Old DB name | Exact database name used by the old GDPS | phpMyAdmin/database panel or the old server configuration. |
| Old DB user | MySQL/MariaDB login for the old database | phpMyAdmin/database panel or the old server configuration. |
| Old DB password | Password for that database user | The password belonging to that MySQL/MariaDB user. |

Examples:

~~~text
Old DB host: 127.0.0.1
Old DB port: 3306
Old DB name: gdps
Old DB user: gdps_user
Old DB password: ********
~~~

Important: the website address and database address are different things. For example, https://example.com is not a MySQL host.

## Where the old GDPS data is stored

The current MegaSa1nt/Cvolton-compatible schema exposes these data areas:

| Data | Source table(s) | Current status |
|---|---|---|
| Accounts | accounts | Imported automatically |
| Player profiles/progress | users | Imported automatically |
| Levels | levels | Imported automatically |
| Classic scores | levelscores | Imported automatically |
| Platformer scores | platscores | Imported automatically |
| Level/account comments | comments, acccomments | Detected and reported; dedicated mapping still required |
| Friends/requests/blocks/messages | friendships, friendreqs, blocks, messages, links | Detected and reported; dedicated mapping still required |
| Lists/Map Packs/Gauntlets/Daily | lists, mappacks, gauntlets, dailyfeatures | Detected and reported; dedicated mapping still required |
| Legacy moderation/admin | roles, roleassign, modips, bannedips, reports, modactions, actions, suggest, modipperms | Detected and reported; not copied into MuchoCore RBAC |
| Song metadata | songs | Detected; binary files need filesystem access |
| Music/SFX files | old server music/ and sfx/ directories | Requires a separate file copy |

This is intentional: Migration Center never reports data as imported when it has only detected it.

## MegaSa1nt / FHGDPS-style sources

MegaSa1nt's public GMDprivateServer is a fork of Cvolton/GMDprivateServer and uses a Cvolton-compatible database layout. The detector therefore identifies the schema family from tables instead of trusting a product name.

A large matching set of tables can be shown as:

~~~text
Family: Cvolton-compatible GDPS schema
Extended signature: MegaSa1nt-style / FHGDPS-compatible Cvolton schema
~~~

The detector does not claim that every database with this schema came from FHGDPS. It only reports the schema compatibility.

## What READY, DETECTED and FILES mean

~~~text
READY
  MuchoCore currently has an automatic database importer for this dataset.

DETECTED
  The old database contains this dataset, but there is no safe automatic
  mapping into the current MuchoCore model yet. Nothing is silently discarded.

FILES
  Database metadata can be inspected, but the real data also exists as files
  on the old server. Database credentials alone are not enough to copy those files.
~~~

## Apply flow

The safe operating sequence is:

~~~text
1. Keep the old GDPS database untouched.
2. Run Migration Center in DRY RUN mode.
3. Read the WHAT IS WHERE section.
4. Check the account/level/score counts.
5. Make a MuchoCore database backup.
6. Run the wizard with --apply.
7. Confirm by typing MIGRATE.
8. Verify the new instance with Mucho Doctor and /health.
~~~

The source database connection is opened with:

~~~sql
SET SESSION TRANSACTION READ ONLY
~~~

Repeated imports use source-to-target account and level mappings so an already imported source record can be reconciled instead of blindly duplicated.

## Password handling

Passwords are not assumed to be portable between unrelated hashing schemes. When a source password is not already a password hash that MuchoCore can verify safely, the imported account receives a random unusable password hash and is marked for a password reset workflow.

The source gjp2 value is retained only as a hash when it matches the expected legacy format.

## Unknown sources

Unknown schemas are rejected:

~~~text
UNSUPPORTED SOURCE SCHEMA
Nothing was changed in MuchoCore.
~~~

The wizard also prints the tables it actually found. This makes it possible to see exactly what differs from the supported family instead of guessing.

## CLI usage

Interactive:

~~~bash
php bin/mucho-migrate.php
~~~

Dry-run with explicit values:

~~~bash
php bin/mucho-migrate.php \
  --source-host=127.0.0.1 \
  --source-port=3306 \
  --source-db=gdps \
  --source-user=gdps_user
~~~

Apply:

~~~bash
MUCHO_MIGRATION_SOURCE_PASS='your-db-password' \
php bin/mucho-migrate.php \
  --source-host=127.0.0.1 \
  --source-port=3306 \
  --source-db=gdps \
  --source-user=gdps_user \
  --apply \
  --confirm=MIGRATE
~~~

Avoid putting database passwords directly into shell history when possible. The interactive wizard can request the password without echoing it.
