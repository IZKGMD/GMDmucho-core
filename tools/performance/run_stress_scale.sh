#!/usr/bin/env bash
set -Eeuo pipefail

# Staged read-workload benchmark for an ALREADY RUNNING isolated stress stack.
# Does not restart containers, build images, disable protection or delete data.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if [[ -f .env ]]; then
  echo "REFUSED: this looks like a production checkout (.env exists)." >&2
  exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is required." >&2
  exit 1
fi
mode="${1:-}"
case "$mode" in
  ""|all) levels="1000 10000" ;;
  --only-1000) levels="1000" ;;
  --only-10000) levels="10000" ;;
  *) echo "Usage: bash tools/performance/run_stress_scale.sh [--only-1000|--only-10000]" >&2; exit 2 ;;
esac

compose() {
  docker compose -f docker-compose.stress.yml "$@"
}
if [[ "$(curl -fsS --max-time 5 http://127.0.0.1:18080/health 2>/dev/null || true)" != "1" ]]; then
  echo "REFUSED: isolated stress stack is not healthy on localhost:18080." >&2
  exit 1
fi

sampler_pid=""
cleanup() {
  if [[ -n "$sampler_pid" ]]; then
    kill "$sampler_pid" 2>/dev/null || true
    wait "$sampler_pid" 2>/dev/null || true
    sampler_pid=""
  fi
}
trap cleanup EXIT INT TERM

for target in $levels; do
  out="storage/stress-reports/scale-$(date -u +%Y%m%d-%H%M%S)-$target"
  mkdir -p "$out"
  echo "========== SYNTHETIC LEVELS: $target =========="
  echo "Generating synthetic data and rebuilding search index..."
  compose exec -T app php tools/performance/seed_stress_scale.php "--levels=$target" \
    | tee "$out/seed.txt"

  # The level-list cache normally lives for 15 seconds. Avoid measuring a
  # stale cached 1k list immediately after growing the test data to 10k.
  echo "Waiting for the preexisting list-cache TTL..."
  sleep 16

  {
    date -u
    free -h
    df -h /
    compose ps
    docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.PIDs}}'
  } > "$out/before.txt" 2>&1

  # Docker stats is sampled only during the workload, so that peak resource
  # usage is not inferred from an idle post-test snapshot.
  (
    while :; do
      date -u +%Y-%m-%dT%H:%M:%SZ
      docker stats --no-stream \
        --format '{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.PIDs}}'
      sleep 5
    done
  ) > "$out/resources.log" 2>&1 &
  sampler_pid=$!

  echo "Measuring 90 seconds of mixed GD read traffic..."
  set +e
  python3 tools/performance/stress_scale.py \
    --levels "$target" --duration 90 --output "$out/report.json" \
    2>&1 | tee "$out/benchmark.log"
  result="$?"
  set -e

  cleanup
  {
    date -u
    free -h
    compose ps
    docker stats --no-stream --format 'table {{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.PIDs}}'
    for service in app db caddy; do
      id="$(compose ps -q "$service" 2>/dev/null || true)"
      if [[ -n "$id" ]]; then
        docker inspect --format 'container={{.Name}} oom_killed={{.State.OOMKilled}} restart_count={{.RestartCount}}' "$id"
      fi
    done
  } > "$out/after.txt" 2>&1

  echo "Scale result ($target levels): $out/report.json"
  echo "Resource samples: $out/resources.log"
  if [[ "$result" != 0 ]]; then
    echo "STRESS_SCALE_FAILED=$target exit=$result; leaving all containers and data intact" >&2
    exit "$result"
  fi
done

echo "STRESS_SCALE_OK"
echo "All results: storage/stress-reports/scale-*/report.json"
