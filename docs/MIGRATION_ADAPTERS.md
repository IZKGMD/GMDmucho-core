# Migration adapters

MuchoCore keeps source migration logic adapter-driven so a database family can be supported without making the whole Migration Center depend on one GDPS layout.

## Architecture

~~~text
SQL dump
  |
  v
SourceDetector
  |
  v
MigrationDatabaseAdapterRegistry
  |
  +--> CvoltonMigrationAdapter
  +--> future database adapters
  |
  v
target mapping / import

Optional full server archive
  |
  v
MigrationServerArchiveAdapterRegistry
  |
  +--> GalaxxyServerArchiveAdapter
  +--> future server/archive adapters
~~~

The database and server-archive adapter layers are intentionally independent. The lower-level level-data adapter remains reusable by archive implementations that only need to restore level payload files.

A source can use a Cvolton-compatible database schema while storing playable level payloads inside SQL. In that case no level-data adapter is needed.

Another source can use the same compatible database schema but keep playable payloads and other persistent data in an external filesystem tree such as:

~~~text
public_html/data/levels/<levelID>
~~~

That source can attach the full server archive and the Galaxxy server adapter restores external level payloads and legacy player Cloud Saves after account/level mappings have been created.

## Database adapter contract

`MigrationDatabaseAdapterInterface` owns database-family behavior:

- source-family support checks;
- read-only preflight;
- dataset counts;
- production import logic.

The registry resolves an adapter from the `SourceDetector` inspection result. The SQL migration service does not instantiate `CvoltonDatabaseImporter` directly.

This keeps the importer implementation specific to Cvolton-compatible schemas while leaving room for another schema family later.

## Server archive adapter contract

`MigrationServerArchiveAdapterInterface` owns a complete source-server archive that accompanies a database import:

- archive detection;
- safe staging of persistent source assets;
- source-to-target hydration;
- cleanup.

The current `GalaxxyServerArchiveAdapter` restores:

- `public_html/data/levels/<levelID>` playable level payloads;
- `public_html/data/accounts/<accountID>` legacy Cloud Save payloads.

It does not copy the old PHP application, configuration, runtime sessions, logs, or executable server code.

Legacy Cloud Saves are re-encrypted into MuchoCore's Cloud Save storage using the server's configured MuchoCore Cloud Save key.

## Level-data adapter contract

`MigrationLevelDataAdapterInterface` owns optional level payloads that live outside the SQL rows:

- archive/file-format detection;
- safe staging;
- payload decoding;
- source-to-target hydration;
- staging cleanup.

The current `GalaxxyFilesystemLevelDataAdapter` delegates archive safety and decoding to `GalaxxyLevelDataArchiveService`.

An empty or missing external archive is a valid state. SQL-only imports do not report external level files as missing.

## Adding a new source

A new database family should add a database adapter rather than expanding Cvolton-specific conditionals.

A new level storage layout should add a level-data adapter rather than changing the SQL migration service.

The intended flow is:

~~~text
new source format
    |
    +--> SourceDetector signature
    |
    +--> MigrationDatabaseAdapter implementation
    |
    +--> registry entry
    |
    +--> contract tests
~~~

For a source that has both a new database schema and a new filesystem layout, implement both adapters independently. The Migration Center can then compose them without introducing source-specific branches into the core workflow.

## Safety rules

Adapters must preserve the existing Migration Center guarantees:

- source inspection remains read-only;
- uploaded SQL is isolated in temporary staging tables;
- unsupported statements are not executed by the SQL importer;
- apply mode creates and verifies a fresh target backup first;
- account and level mappings are persisted before dependent data is written;
- external files are staged inside the migration storage directory;
- cleanup is safe to repeat;
- unknown schemas fail closed instead of guessing.

When an adapter cannot safely identify or map a source, it should reject the migration and report the detected schema rather than silently dropping data.
