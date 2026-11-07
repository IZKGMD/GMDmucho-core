# Isolated VPS stress baseline (Ubuntu 22.04 / 24.04)

This is a deliberately bounded, synthetic, localhost-only benchmark of
MuchoCore PHP8 and GD 2.209 read endpoints. It does not need a public domain
or any real player accounts.

Do not use an existing production checkout: the launch script refuses to run
if that checkout has an .env file. Work in a separate test directory.

## Prerequisites

Ubuntu 22.04 or 24.04, Docker Engine with Docker Compose v2, Python 3, curl,
OpenSSL and git. For production, prefer Docker's official apt repository;
the following convenience script is suitable for a disposable VPS
after you inspect and trust it:

~~~bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git openssl python3
curl -fsSLo /tmp/get-docker.sh https://get.docker.com
sudo sh /tmp/get-docker.sh
docker compose version
~~~

Clone the test branch to a dedicated location:

~~~bash
git clone --depth 1 \
  https://github.com/IZKGMD/GMDmucho-core.git /opt/mucho-stress
cd /opt/mucho-stress
bash tools/performance/run_stress_local.sh
~~~

The launcher creates private passwords in .stress-secrets (ignored by Git),
pulls a prebuilt PHP 8.3 FPM runtime from GitHub Container Registry
(ghcr.io/izkgmd/gmdmucho-core:stress), and starts a separate MariaDB 11.4 +
PHP 8.3 FPM + Caddy stack, and binds Caddy
only to 127.0.0.1:18080. No ports are exposed on the public VPS address.
It seeds one synthetic StressRobot account and Synthetic Stress Level (ID 1)
in a dedicated mucho_stress database, then performs:

- HTTP /health at concurrency 1, 2, 4, and 8.
- Paced GD 2.209 level list and ID=1 search requests, with response checks.
- Resource snapshots before and after, including OOM/restart flags.

The prebuilt image is published by the Publish stress runtime image GitHub
Actions workflow; build time and compilation memory usage are offloaded to
GitHub runners. If a public pull returns 403/denied, the repository owner
must configure the GHCR package visibility as public in GitHub Packages
before unauthenticated hosts can pull it. Check that the GitHub workflow
has published the image before trying to run on VPS.

No fake client IP or CF-Connecting-IP headers are sent. GD search is paced
every 700 ms, below the default MuchoProtect per-IP search rate limit.
This is a baseline responsiveness check, not a maximum-search-throughput
measurement, native-client certification or proof of how many players fit.

JSON results and resource snapshots go under:

~~~text
storage/stress-reports/YYYYMMDD-HHMMSS/report.json
storage/stress-reports/YYYYMMDD-HHMMSS/before.txt
storage/stress-reports/YYYYMMDD-HHMMSS/after.txt
~~~

You can run a shorter smoke test or include the optional worker:

~~~bash
python3 tools/performance/stress_local.py --quick \
  --output storage/stress-reports/quick.json

MUCHO_STRESS_WITH_WORKER=1 bash tools/performance/run_stress_local.sh
~~~

## Inspect and stop

~~~bash
cat storage/stress-reports/*/report.json
cat storage/stress-reports/*/after.txt
docker compose -f docker-compose.stress.yml ps
docker compose -f docker-compose.stress.yml logs --tail 80 app db caddy

# Stop without deleting the test database:
docker compose -f docker-compose.stress.yml down
# Optional, deletes ONLY the synthetic stress volumes:
docker compose -f docker-compose.stress.yml down -v
~~~

A peak capacity test needs realistic data, a separate authorization-safe load
profile and careful CPU/SQL measurement. Do not use the old integration
load.sh for this test: it sends synthetic client IP headers, so results do
not reflect MuchoProtect's real client rate limits.


## Verified VPS measurements

A real 1k→10k mixed GD 2.209 read workload, combined with a separately tested
visible-level index, was run on 2026-11-07. See
[measured p95 timings, query plans, PHP8/PHP7 reindex refactor and caveats](PERFORMANCE_VPS_1K_10K_20261107.md).

The staging index improved text search and latest-list p95 in a single 10k
fixture run; it is **not** enabled automatically on existing installations.

## Phase 2: 1,000 versus 10,000 levels

After phase 1 has passed and its containers are still up, update the test
checkout to the new main branch. Do not reset or delete its existing
synthetic Docker volumes:

~~~bash
cd /opt/mucho-stress
git pull --ff-only
bash tools/performance/run_stress_scale.sh
~~~

Run it in tmux on low-memory servers so an SSH disconnect does not end it:

~~~bash
tmux new-session -d -s mucho-scale \
  'cd /opt/mucho-stress && bash tools/performance/run_stress_scale.sh > /root/mucho-scale.log 2>&1; echo "EXIT_CODE=$?" >> /root/mucho-scale.log'
tail -f /root/mucho-scale.log
~~~

This phase uses the **running, localhost-only test stack**. It does not
build or pull images, reset data, disable MuchoProtect or restart PHP/MariaDB.
For each fixture size (1,000, then 10,000), it:

1. Seeds deterministic level IDs in the existing **mucho_stress** database
   using small transactions. The script refuses non-stress databases and any
   unexpected pre-existing player/level fixtures.
2. Rebuilds the canonical level search index and verifies its row count.
3. Lets the short-lived response cache expire.
4. Measures 90 seconds of paced GD 2.209 *read* traffic: level list, lookup
   by ID, text search, download with inc=0, and public user profile. Each route must
   return a genuine matching synthetic response, not an empty response or -1.
5. Samples container memory/CPU every 5 seconds **during** the workload
   and records post-test OOM/restart information.

The request start interval is deliberately 450 ms, with five scenarios
cycled (three share the level-search endpoint). Level-search receives about
80 requests/minute and download/profile about 27/minute, below default
per-IP request limits. The reported throughput is **client-pacing-limited**;
it is neither maximum PHP throughput nor a simulation of 10,000 concurrent
players. No source-IP spoofing, uploads, admin mutations or score writes.

The reports are under:

~~~text
storage/stress-reports/scale-*-1000/report.json
storage/stress-reports/scale-*-1000/resources.log
storage/stress-reports/scale-*-10000/report.json
storage/stress-reports/scale-*-10000/resources.log
~~~

For a repeat of only the larger fixture, which preserves the already seeded
10,000 levels:

~~~bash
bash tools/performance/run_stress_scale.sh --only-10000
~~~

Once 10,000 levels are seeded, **do not** rerun the 1,000-level scenario on
the same volume and compare it as if the dataset shrank; the seeder refuses
that downgrade rather than deleting rows. Separate clean stress volumes are
needed for a fresh 1k/10k comparison.

Compare p50/p95/p99 for matching routes, failures, peak memory, swap,
OOM/restarts and resource-sampling CPU across the two fixture sizes. On a
1-vCPU, 1-GiB server, synthetic data can remain small while PHP and MariaDB
compete for one CPU. A valid capacity study requires additional
representative data, genuine concurrent client identities and careful
rate-limit-aware testing on a non-public staging tenant.
