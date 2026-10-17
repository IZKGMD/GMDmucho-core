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
   php bin/mucho-gd2209-trace-report.php --min-binary=51 --json
   ```
   The value `51` is documented for current 2.209 PC/mobile builds by GD Docs; verify the value in the captured request before filtering.
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
# MariaDB isolated integration test:
php tests/application/gd2209-moderation-queue-integration.php
php tests/protocol/router-compatibility.php
```

### What remains for a verifiable 100% claim

- Record redacted request and response fixtures from **real** Windows
  and Android 2.209 clients for every new endpoint.
- Confirm moderator level-search `type=8` and sent-history `type=27` in the real client.
- Confirm `getLevelRateInfo`, `getGJContentStatus`,
  `getGJSongUsage`, `reportGJContent` and all moderation queue paths.
- Validate notification, thread, audio and level-data serialization
  against real client acceptance, not merely server-side unit tests.
- Test startup, login, levels, profile, cloud save/sync and new features
  on both patched platforms; run regression and performance suites.
- Rebuild MuchoClient only against confirmed Geode 2.209 bindings.

## Moderation list correction

The legacy GD level search endpoint uses `type=8` for moderator sent
levels and `type=27` for an advisor's sent history (GD Docs). The server
now requires a valid moderator credential for both. Type 8 lists unrated,
listed, undeleted levels with recorded suggestions; type 27 lists only
suggestions sent by the authenticated advisor, including their rated history.
Both support name/ID search and the GD `levels#creators#songs#pagination#hash`
wire shape. Requested stars use the newest recorded suggestion for those
list types. This is a **server implementation**, not verified 2.209
moderation popup acceptance. Reproduce in a patched client and inspect
traces; failure can also originate from other endpoints or permissions.
