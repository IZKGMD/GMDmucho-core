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
