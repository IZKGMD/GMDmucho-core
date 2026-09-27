# MuchoCore v1.0.8 — Intelligence & Scale

MuchoCore v1.0.8 adds a new operational layer on top of the existing protocol, security, migration and administration foundation.

## Level Intelligence

Level writes now pass through MuchoCore\Level\LevelValidator.

The validator checks structural limits before a level is stored:

- account ownership identifiers;
- level name size, UTF-8 validity and control characters;
- level payload presence, size and NUL bytes;
- game and binary version ranges;
- object-count sanity;
- description, level-info, settings, extra-string and song-list size limits.

The validator returns a SHA-256 fingerprint, errors and non-fatal warnings. It does not attempt to guess undocumented Geometry Dash compression rules.

## Search index

mucho_level_search_index is derived from the canonical levels table.

It stores normalized level names and creator names and is populated during migration. New and updated levels refresh their index entry automatically.

Rebuild the complete index:

~~~bash
sudo ./bin/mucho search rebuild
~~~

Search it directly:

~~~bash
sudo ./bin/mucho search search "bloodbath" 20
~~~

The runtime keeps the legacy SQL search path as a fallback when the derived index is unavailable.

Public level-list responses also support a short configurable cache:

~~~text
MUCHO_CACHE_DRIVER=database
MUCHO_LEVEL_CACHE_TTL=15
~~~

For larger deployments an external Redis cache is supported when PHP has the Redis extension:

~~~text
MUCHO_CACHE_DRIVER=redis
MUCHO_REDIS_HOST=127.0.0.1
MUCHO_REDIS_PORT=6379
MUCHO_REDIS_DATABASE=0
~~~

Set MUCHO_CACHE_DRIVER=none to disable caching.

## Level revisions

mucho_level_revisions stores compressed level payload snapshots and metadata fingerprints.

List revisions:

~~~bash
sudo ./bin/mucho level revisions 12345
~~~

Validate a stored level:

~~~bash
sudo ./bin/mucho level validate 12345
~~~

Restore a revision:

~~~bash
sudo ./bin/mucho level restore 12345 2 --confirm
~~~

The restore path is transactional and refreshes the derived search index afterwards.

## Background jobs

MuchoCore now includes a MariaDB-backed queue in mucho_jobs.

The built-in worker currently supports:

- level index refresh;
- signed webhook delivery;
- maintenance cleanup of completed jobs and expired cache records.

Run a single worker pass:

~~~bash
sudo ./bin/mucho worker
~~~

Run continuously:

~~~bash
sudo ./bin/mucho worker --loop
~~~

Docker Compose includes a dedicated worker service in v1.0.8.

Jobs retry failed processing and stale running jobs can be reclaimed safely.

## Signed webhooks

Optional operator webhooks are configured with:

~~~text
MUCHO_WEBHOOK_URL=
MUCHO_WEBHOOK_SECRET=
MUCHO_WEBHOOK_EVENTS=*
~~~

Events emitted by the new lifecycle integrations include:

~~~text
account.registered
level.uploaded
level.updated
level.deleted
~~~

When a secret is configured, MuchoCore adds:

~~~text
X-MuchoCore-Signature: sha256=<HMAC-SHA256>
~~~

Webhooks are delivered by the background worker so protocol responses do not wait on the remote endpoint.

## Protocol trace analysis

MuchoCore continues to use its existing safe client trace mechanism. v1.0.8 adds request IDs, response length, response SHA-256 and request duration to trace entries without storing response bodies.

Inspect a trace:

~~~bash
sudo ./bin/mucho trace inspect /var/www/mucho-core/storage/client-trace.ndjson
~~~

Compare two traces:

~~~bash
sudo ./bin/mucho trace diff old.ndjson new.ndjson
~~~

The diff compares protocol signatures rather than exposing raw response payloads.

## End-to-end smoke test

Run:

~~~bash
sudo ./bin/mucho test
~~~

The test verifies database connectivity and v1.0.8 intelligence tables. When MUCHO_E2E_BASE_URL is explicitly configured, it additionally checks health and a read-only level-list request.

Production mutation is never performed by this test.

## Backup verification

Inspect all local database backups:

~~~bash
sudo ./bin/mucho backup-verify
~~~

Each .sql.gz file is checked with gzip -t, sampled for plausible SQL content and fingerprinted with SHA-256. Verification history is stored in mucho_backup_verifications when the database is available.

This is an integrity check, not a substitute for a real disaster-recovery restore rehearsal.

## Admin Control Center

The Admin Panel now exposes Intelligence & Scale.

The page reports:

- indexed levels;
- visible levels missing an index row;
- revision count;
- queued/running/failed jobs;
- cache records;
- backup verification records;
- operator commands for rebuilding and diagnostics.

## Compatibility philosophy

The new subsystems are deliberately additive.

If cache, search-index or background infrastructure is unavailable, core Geometry Dash protocol paths retain their existing fallback behavior wherever practical. Derived data is rebuildable and background processing is isolated from successful protocol responses.

This keeps small deployments simple while giving larger GDPS installations an upgrade path for indexing, caching and asynchronous work.
