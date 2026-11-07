#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

# Dedicated test checkout only: never attach the stress stack to a live tenant.
if [[ -f .env ]]; then
  echo "REFUSED: .env exists in this checkout. Clone MuchoCore into a separate test directory." >&2
  exit 1
fi
for tool in docker openssl python3 curl; do
  command -v "$tool" >/dev/null 2>&1 || {
    echo "Missing prerequisite: $tool" >&2
    exit 1
  }
done
docker compose version >/dev/null || {
  echo "Docker Compose v2 plugin is required." >&2
  exit 1
}
compose() {
  docker compose -f docker-compose.stress.yml "$@"
}

install -d -m 700 .stress-secrets
for name in db_password db_root_password; do
  if [[ ! -s ".stress-secrets/$name" ]]; then
    openssl rand -hex 24 > ".stress-secrets/$name"
  fi
  chmod 600 ".stress-secrets/$name"
done
compose config -q

echo "[MuchoCore] Building local, isolated stress stack..."
compose build --pull app
compose up -d db app caddy

healthy=0
for attempt in $(seq 1 80); do
  if [[ "$(curl -fsS --max-time 4 http://127.0.0.1:18080/health 2>/dev/null || true)" == "1" ]]; then
    healthy=1
    break
  fi
  sleep 2
done
if [[ "$healthy" != "1" ]]; then
  echo "Stress stack did not become healthy. Recent logs:" >&2
  compose logs --tail 60 app db caddy >&2
  exit 1
fi

echo "[MuchoCore] Seeding synthetic GD 2.209 account and level..."
compose exec -T app php tools/performance/seed_stress_fixture.php
if [[ "$(printenv MUCHO_STRESS_WITH_WORKER || true)" == "1" ]]; then
  docker compose -f docker-compose.stress.yml --profile worker up -d worker
fi

mkdir -p storage/stress-reports
out="storage/stress-reports/$(date -u +%Y%m%d-%H%M%S)"
mkdir -p "$out"

{
  date -u
  uname -a
  free -h
  df -h /
  compose ps
  docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}'
} > "$out/before.txt" 2>&1

echo "[MuchoCore] Running bounded localhost-only benchmark..."
set +e
python3 tools/performance/stress_local.py --output "$out/report.json"
test_result=$?
set -e

{
  date -u
  free -h
  compose ps
  docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}'
  for service in db app caddy worker; do
    id="$(compose ps -q "$service" 2>/dev/null || true)"
    if [[ -n "$id" ]]; then
      docker inspect --format 'container={{.Name}} oom_killed={{.State.OOMKilled}} restart_count={{.RestartCount}}' "$id"
    fi
  done
} > "$out/after.txt" 2>&1

echo
echo "========================================"
echo "MUCHOCORE LOCAL STRESS TEST COMPLETED"
echo "Results: $out/report.json"
echo "Before:  $out/before.txt"
echo "After:   $out/after.txt"
echo "To stop: docker compose -f docker-compose.stress.yml down"
echo "To REMOVE the synthetic test DB: docker compose -f docker-compose.stress.yml down -v"
echo "========================================"
exit "$test_result"
