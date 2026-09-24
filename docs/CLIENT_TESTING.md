# Geometry Dash 2.2 Client Verification

MuchoCore's final 2.2 release gate is based on a real Geometry Dash 2.2 client trace.

## Step 1: enable tracing

On the VPS:

```bash
cd /opt/mucho-core
sudo nano .env
```

Set:

```dotenv
MUCHO_CLIENT_TRACE=1
MUCHO_CLIENT_TRACE_FILE=/var/www/mucho-core/storage/client-trace.ndjson
```

Restart the application:

```bash
sudo docker compose up -d
```

The trace records request method, endpoint path, response status, query keys, POST keys, client family, game version and binary version. It does not record request values or passwords.

## Step 2: run a real Geometry Dash 2.2 client

Use the patched Geometry Dash 2.2 client and exercise the normal account, profile, level browser, level download/upload, comments, ratings, leaderboards, level lists, friends, messages, daily/gauntlets/map packs, rewards, Secret Room/Wraith, custom songs and cloud-save flows.

Use a dedicated test account.

## Step 3: disable tracing

Set `MUCHO_CLIENT_TRACE=0` in `/opt/mucho-core/.env` and restart:

```bash
cd /opt/mucho-core
sudo docker compose up -d
```

## Step 4: generate the 2.2 fixture

```bash
cd /opt/mucho-core
python3 tools/client/client-trace-summary.py \
  --expected-family 2.2 \
  --input storage/client-trace.ndjson \
  --output tests/client-fixtures/2.2/endpoints.json
```

## Step 5: run the release gate

```bash
bash tests/release/release-2.2-gate.sh
bash tests/client/client-contract.sh
```

The fixture must be generated from a real 2.2 trace. Do not hand-author it from documentation or router aliases.

## Release rule

Automated protocol tests plus a green CI run are not enough for the final 2.2 claim. `tests/client-fixtures/2.2/endpoints.json` must exist and contain only `2.2` trace entries.

Until that fixture is committed and the release gate passes, the project should be described as server-side 2.2 compatibility verified, with real-client compatibility still pending.
