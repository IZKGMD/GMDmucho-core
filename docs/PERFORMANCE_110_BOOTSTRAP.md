# MuchoCore — PHP bootstrap and DB connection measurement

## Real VPS baseline, October 20, 2026

- 1 level stored, so SQL scans/filesorts are not the current bottleneck.
- HTTP \`getGJLevels21\` real 1-level response on unoptimized \`main\`:
  accepted 50/50 requests, warm p50 26.836–29.197 ms,
  p95 40.106–50.418 ms, paced every 600 ms.
- CLI PHP 8.3.35 via \`docker compose exec -T app php -r\`:
  (new Database())->connection() from preloaded composer autoload:
  9.330 ms; twenty \`SELECT 1\` queries: 5.155 ms (~0.258 ms each).

**Those samples do not isolate network/DNS, environment parsing or PHP-FPM.**
Do not claim that 9.330 ms is always the server's DB connection overhead,
or that 26–50 ms is purely PHP. Host-container Docker \`exec\` startup is
outside the original script's timed region.

## Safe next step without changing a live checkout

On the existing VPS, use the exact performance-branch script piped to the
currently installed application container:

\`\`\`bash
cd /opt/mucho-core
git fetch origin perf/global-core-20261019
git show FETCH_HEAD:tools/performance/db_bootstrap_probe.php \
  | docker compose exec -T app php
\`\`\`

Repeat **three independent executions**, ideally during an idle period.
The script performs only 20 read-only \`SELECT 1\` queries and does not
expose passwords, IPs, user records or raw environment values.

It reports \`autoload_ms\`, \`configuration_ms\`, \`pdo_connect_ms\`,
\`twenty_select_1_ms\`, \`per_query_ms\` and \`total_ms\`.
\`configuration_ms\` covers project/runtime dotenv lookup and parsing;
\`pdo_connect_ms\` covers PDO setup, local Docker DNS/TCP handshake and
MariaDB authentication. Neither proves a single root cause.

This is a PHP **CLI** microbenchmark. The production PHP-FPM pool has
different OPcache, worker lifecycle and connection-reuse behavior. Compare
the same schema/workload at staging before altering DB connectivity.

## Safe code-path change in this PR

The front controller now reuses its already-inspected, immutable
\`Request\` when calling \`Application::run($request)\`, avoiding a second
\`Request::fromGlobals()\` call. \`Application::run()\` still accepts no
argument (backward compatible), and MuchoProtect still runs before dispatch.

## Next optimization candidates only after evidence

- If config parsing dominates: further reduce dotenv reads under FPM.
- If PDO connect dominates: compare carefully scoped FPM worker connection
  reuse on an isolated staging instance and monitor MariaDB connection count.
  Do **not** enable unlimited persistent connections on the 1 GiB VPS by default.
- If application bootstrap dominates: consider lazy-loading handlers and
  plugin initialization while keeping all registered routes and lifecycle
  events intact.
- If security precheck dominates: profile it without weakening rate limits
  or temporary abuse penalties.

Never switch the live checkout, disable MuchoProtect, or execute migrations
just to run this diagnostic.
