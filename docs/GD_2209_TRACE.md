# GD 2.209 — real-client compatibility diagnostics

This is **not** a claim of 100% native compatibility. The Windows 2.209
EXE URL patch is separate from API parity; several new endpoints use
provisional server-side request/response formats pending native captures.

The GD game reports `gameVersion=22` for the 2.2 family, including 2.209.
Do **not** assume every 2.2 request is 2.209; note the `binaryVersion` in
the actual captured request.

## Diagnose failed moderation queue (and other GD 2.209 screens)

1. Update the server code to the branch containing this diagnostic.
2. Set `MUCHO_CLIENT_TRACE=1` on the PHP-serving process (not just a CLI
   shell). To use a writable custom path, also set `MUCHO_CLIENT_TRACE_FILE`.
3. Reproduce **Moderation Queue → Failed to load the queue** once and retry.
4. Run:
   ```sh
   php bin/mucho-gd2209-trace-report.php
   # If the new client's binaryVersion is known from your trace:
   php bin/mucho-gd2209-trace-report.php --min-binary=49 --json
   ```
   The number `49` is an example, **not** a verified 2.209 binaryVersion.
5. Compare rejected paths with `src/Core/Application.php` routes. If
   `getGJLevels21` fails, inspect request *field names* and moderation
   filter semantics; if an unregistered endpoint fails, implement that
   endpoint. Do not invent response data for an unknown wire contract.

The trace stores timestamp, method, route, safe **field names only**, HTTP
status, response length, SHA-256 hash and a categorical wire result
(`rejected` for literal `-1`, `no_results` for `-2`,
`http_error`, `empty` or `data`). No POST/GET values, passwords,
`gjp2`, session tokens, request bodies or response bodies are stored.
The trace still reveals which endpoints were used and when; treat it as
private diagnostic data and disable tracing afterwards.

`getGJUserSearch` currently reuses the legacy read-only user search
serialization, accepting `str`, `search` or `query`. **The native 2.209
request and response format is unverified.** The server returns the usual
GD failure marker on invalid input or backend failure instead of inventing
successful data.

Validation:
```sh
php tests/client/gd-2209-trace.php
php tests/protocol/router-compatibility.php
```

### What remains for a verifiable 100% claim

- Record redacted request and response fixtures from **real** Windows
  and Android 2.209 clients for every new endpoint.
- Confirm `getLevelRateInfo`, `getGJContentStatus`,
  `getGJSongUsage`, `reportGJContent` and all moderation queue paths.
- Validate notification, thread, audio and level-data serialization
  against real client acceptance, not merely server-side unit tests.
- Test startup, login, levels, profile, cloud save/sync and new features
  on both patched platforms; run regression and performance suites.
- Rebuild MuchoClient only against confirmed Geode 2.209 bindings.
