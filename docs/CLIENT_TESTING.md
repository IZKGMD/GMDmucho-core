# Geometry Dash Client Verification

MuchoCore keeps separate real-client gates for each compatibility generation. Server-side protocol implementation can be complete before empirical runtime verification is captured.

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

Restart:

```bash
sudo docker compose up -d
```

The trace records request method, endpoint path, response status, query keys, POST keys, client family, game version and binary version. It does not record request values or passwords.

## Step 2: run the client generation being verified

Use a real client for the target generation:

| Target | Expected family | Fixture | Gate |
| --- | --- | --- | --- |
| GD 1.0 | `1.0` | manual smoke verification | ✅ passed |
| GD 1.1 | legacy 1.x | manual smoke verification | ✅ passed |
| GD 1.2 | legacy 1.x | manual smoke verification | ✅ passed |
| GD 1.3 | legacy 1.x | manual smoke verification | ✅ passed |
| GD 1.4 | legacy 1.x | manual smoke verification | ✅ passed |
| GD 1.5 | `1.5` | manual smoke verification | server compatibility + protocol contracts |
| GD 1.6 | legacy 1.x | manual smoke verification | server compatibility + protocol contracts |
| GD 1.9 | `1.9` | `tests/client-fixtures/1.9/endpoints.json` | `tests/release/release-1.9-gate.sh` |
| GD 2.0 | `2.0` | `tests/client-fixtures/2.0/endpoints.json` | `tests/release/release-2.0-gate.sh` |
| GD 2.1 | `2.1` | `tests/client-fixtures/2.1/endpoints.json` | `tests/release/release-2.1-gate.sh` |
| GD 2.2 | `2.2` | `tests/client-fixtures/2.2/endpoints.json` | `tests/release/release-2.2-gate.sh` |

For GD 1.5 and GD 1.6, exercise the legacy paths that are currently in scope: account/profile access, level search, level upload, level update checks, comments, and score/profile updates.

For the fixture-backed generations, exercise the normal flows supported by that generation: account, profile, level browser, level download/upload, comments, ratings, leaderboards, friends/messages where present, and cloud-save. Use a dedicated test account.

## Step 3: disable tracing

After the client run:

```bash
cd /opt/mucho-core
sudo sed -i 's/^MUCHO_CLIENT_TRACE=1/MUCHO_CLIENT_TRACE=0/' .env
sudo docker compose up -d
```

Keep the trace file until the fixture has been generated and validated.

## Step 4: generate the matching fixture

For GD 1.9:

```bash
python3 tools/client/client-trace-summary.py \
  --expected-family 1.9 \
  --input storage/client-trace.ndjson \
  --output tests/client-fixtures/1.9/endpoints.json
```

For GD 2.0:

```bash
python3 tools/client/client-trace-summary.py \
  --expected-family 2.0 \
  --input storage/client-trace.ndjson \
  --output tests/client-fixtures/2.0/endpoints.json
```

For GD 2.1, replace `2.0` with `2.1` in both family and fixture path. For GD 2.2, replace it with `2.2`.

Never combine traces from different Geometry Dash generations.

## Step 5: run the matching release gate

GD 1.9:

```bash
bash tests/release/release-1.9-gate.sh
bash tests/client/client-contract.sh
```

GD 2.0:

```bash
bash tests/release/release-2.0-gate.sh
bash tests/client/client-contract.sh
```

GD 2.1:

```bash
bash tests/release/release-2.1-gate.sh
bash tests/client/client-contract.sh
```

GD 2.2:

```bash
bash tests/release/release-2.2-gate.sh
bash tests/client/client-contract.sh
```

## Current 1.0 verification status

A real Geometry Dash 1.0 client has been verified against MuchoCore on the shared early legacy 1.x compatibility path.

## Current 1.1 verification status

A real Geometry Dash 1.1 client has been verified against MuchoCore on the shared early legacy 1.x compatibility path.

## Current 1.2–1.4 verification status

Real Geometry Dash 1.2, 1.3, and 1.4 clients have been verified against the shared MuchoCore legacy 1.x compatibility path. Marked ✅ as completed manual smoke verification.

## Current 1.6 verification status

A real Geometry Dash 1.6 build 16 client has been patched and smoke-tested against MuchoCore. The patched client completed the supported server flows end-to-end.

## Current 1.5 verification status

A real Geometry Dash 1.5 build 13 client has been smoke-tested against MuchoCore. The verified flow includes level search, level upload, level update handling, comment submission, and the legacy UDID-based `updateGJUserScore` path.

The repository has automated regression coverage for the 1.5 compatibility boundary. A committed real-client fixture is not required for this manual smoke gate.

## Current 2.2 verification status

The GD 2.2 gate is currently satisfied by the committed `tests/client-fixtures/2.2/endpoints.json` fixture. Its provenance is `tools/client-trace-summary.py`, its trace family is `2.2`, and its recorded client metadata is game version `22` / binary version `47` (GD 2.2.13).

The 2.2 release gate validates this contract in CI. The fixture is the regression baseline for future protocol changes.

## Release rule

Automated protocol tests and a green CI run are paired with the corresponding real-client fixture. For manual smoke gates such as GD 1.5, the verification record identifies the tested build and the exercised flows. A final fixture-backed client claim requires the fixture to exist, be generated by the trace tooling, contain only the expected client family, and pass its release gate.
