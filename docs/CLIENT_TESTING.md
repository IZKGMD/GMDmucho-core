# Geometry Dash Client Verification

MuchoCore keeps separate real-client gates for each compatibility generation. GD 2.0 and 2.1 are currently implemented server-side; real-client traces are used to verify runtime behavior.

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

## Step 2: run a real Geometry Dash client

Use the client generation being verified. For GD 2.0, use a 2.0 client with `gameVersion=20` and the normal 2.0 binary. For GD 2.1, use a 2.1 client. For GD 2.2, use the established 2.2 client fixture.

Exercise the normal flows supported by that generation: account, profile, level browser, level download/upload, comments, ratings, leaderboards, friends/messages where present, and cloud-save. Use a dedicated test account.

Use a dedicated test account.

## Step 3: disable tracing

Set `MUCHO_CLIENT_TRACE=0` in `/opt/mucho-core/.env` and restart:

```bash
cd /opt/mucho-core
sudo docker compose up -d
```

## Step 4: generate the fixture

```bash
cd /opt/mucho-core
python3 tools/client/client-trace-summary.py \
  --expected-family 2.0 \
  --input storage/client-trace.ndjson \
  --output tests/client-fixtures/2.0/endpoints.json
```

## Step 5: run the release gate

```bash
bash tests/release/release-2.0-gate.sh
bash tests/client/client-contract.sh
```

The fixture must be generated from a real client trace. Do not hand-author it from documentation or router aliases.

## Release rule

Automated protocol tests plus a green CI run are not enough for a final real-client claim. The corresponding fixture must exist and contain only the expected family. For GD 2.0 that is `tests/client-fixtures/2.0/endpoints.json` with only `2.0` trace entries.

The same rule applies to the 2.1 and 2.2 fixtures.
