# Testing Real Geometry Dash Client Compatibility

The server has automated tests, but a real Geometry Dash client must still be tested once.

The project does not claim full client compatibility until that test has been performed.

## Step 1: turn on client tracing

On the VPS:

```bash
cd /opt/mucho-core
sudo nano .env
```

Add:

```dotenv
MUCHO_CLIENT_TRACE=1
MUCHO_CLIENT_TRACE_FILE=/var/www/mucho-core/storage/client-trace.ndjson
```

Restart the application:

```bash
sudo docker compose up -d
```

The trace records:

- request method;
- endpoint path;
- response status;
- query parameter names;
- POST parameter names.

It does **not** record request parameter values or passwords.

## Step 2: run the real client

Use your real Geometry Dash client and perform normal actions:

1. Open the client.
2. Log in or create a test account.
3. Open the level browser.
4. Download a level.
5. Upload a test level.
6. Open and post a comment.
7. Submit a score.
8. Open the social/friends area.
9. Send a message if your test account supports it.
10. Test cloud save.

Do not use a real personal account for testing. Use a dedicated test account.

## Step 3: turn tracing off

On the VPS:

```bash
sudo nano /opt/mucho-core/.env
```

Change:

```dotenv
MUCHO_CLIENT_TRACE=0
```

Restart:

```bash
cd /opt/mucho-core
sudo docker compose up -d
```

## Step 4: generate the client contract

From the MuchoCore repository:

```bash
python3 tools/client-trace-summary.py \
  --input storage/client-trace.ndjson \
  --output tests/client-fixtures/endpoints.json
```

This creates a small JSON file containing every unique endpoint the real client used during the test.

## Step 5: verify the contract

Run:

```bash
bash tests/client-contract.sh
```

Expected result:

```text
Client contract OK: N endpoint(s)
```

The test checks that every endpoint seen by the real client still exists in MuchoCore.

Commit the generated contract file:

```text
tests/client-fixtures/endpoints.json
```

From that point onward, GitHub Actions will automatically check that future changes do not remove an endpoint that the real client actually used.

## What this proves

The client contract proves that the real tested client reached these endpoints and that MuchoCore still exposes them.

It does **not** prove every possible client feature works.

That is why the project keeps two separate layers:

```text
Automated regression tests
        +
Real client endpoint contract
        +
Manual real-client test
```

## When to repeat the real client test

Repeat it when:

- Geometry Dash changes its protocol;
- MuchoCore changes account or level routing;
- cloud-save behavior changes;
- a new client version is supported.

Do not claim support for a new client version until it has been tested.

## Current status

Until a real client trace is committed, the repository should be described as:

**Server compatibility tested; real client compatibility not yet verified.**
