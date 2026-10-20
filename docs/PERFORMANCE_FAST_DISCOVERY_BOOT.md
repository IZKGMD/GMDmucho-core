# MuchoCore — optional lightweight GD discovery bootstrap

This experiment reduces per-request PHP object construction for selected
public Geometry Dash discovery endpoints. It is **off by default**. It makes
no SQL schema, cache, permission or wire format changes.

## Routes

- `getGJSongInfo` and `getGJTopArtists`
- `getGJCreators`, `getGJCreators19`, `getGJDailyLevel`
- `getGJGauntlets`, `getGJGauntlets21` (including normalized aliases)
- `getGJMapPacks`, `getGJMapPacks20`, `getGJMapPacks21`

These routes call the **existing production controllers** with their existing
repository, encoder and SQL logic. A request must be prechecked by the usual
front controller/MuchoProtect first. CompatibilityProfile, ClientTrace and
plugin server.boot, request.received/completed hooks still run; plugin routes
continue to be registered but cannot replace the selected core handler.

Only a single selected core route is registered. All other requests,
standalone `new Application()` calls, and deployments without the flag use
the complete historical route graph. No SQL connection is skipped; this
optimizes PHP initialization, not database/network latency.

## Safe rollbackable A/B on a staging tenant

Use **one** non-mutating route and the same seeded dataset, accepted response,
PHP build, cache settings and host header for each run. Example:

```bash
cd /opt/mucho-core
# Baseline: feature off.
docker compose up -d --no-deps --force-recreate app
python3 tools/performance/http_latency.py \
  --url http://127.0.0.1/database/getGJSongInfo.php \
  --host-header staging.example.test --method POST \
  --data 'songID=1&gameVersion=22&binaryVersion=51' \
  --count 100 --warmup 5 --concurrency 1 --interval-ms 600

# Candidate: feature on in only the app container.
MUCHO_FAST_DISCOVERY_BOOT=1 docker compose up -d --no-deps --force-recreate app
docker compose exec -T app printenv MUCHO_FAST_DISCOVERY_BOOT
# Repeat the identical benchmark, verify wire equality.

# Roll back to original runtime (no volumes or database touched).
docker compose up -d --no-deps --force-recreate app
docker compose exec -T app printenv MUCHO_FAST_DISCOVERY_BOOT
```

Expect the flag to be `1` during candidate and `0` after rollback.
Do not interpret the benchmark's 600ms-paced RPS as server capacity.
Do not compare requests that return `-1` because a song/row is absent.
Prefer A/B/A or A/B/B/A to control VPS noise, plus native GD 2.209
smoke tests and plugin checks before considering the flag for production.
