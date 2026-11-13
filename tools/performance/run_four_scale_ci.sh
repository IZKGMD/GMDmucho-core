#!/usr/bin/env bash
set -Eeuo pipefail

# Only GitHub's isolated CI host may run this fully automated four-stage test.
# No production checkout, player data, public network or external hostname.
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
if [[ "$GITHUB_ACTIONS" != true || -f .env ]]; then
  echo "REFUSED: requires an isolated GitHub runner without production .env" >&2
  exit 2
fi

compose() {
  docker compose -f docker-compose.stress.yml "$@"
}
if [[ "$(curl -fsS --max-time 5 http://127.0.0.1:18080/health || true)" != 1 ]]; then
  echo "REFUSED: localhost stress server is not healthy" >&2
  exit 1
fi

report_root="$RUNNER_TEMP/ci-four-scale"
mkdir -p "$report_root"
sampler_pid=""
cleanup_sampler() {
  if [[ -n "$sampler_pid" ]]; then
    kill "$sampler_pid" 2>/dev/null || true
    wait "$sampler_pid" 2>/dev/null || true
    sampler_pid=""
  fi
}
trap cleanup_sampler EXIT INT TERM

for size in 1000 2000 5000 10000; do
  dir="$report_root/$size"
  mkdir -p "$dir"
  echo "===== SYNTHETIC STAGE: $size accounts + $size levels ====="

  # Account password hashes are deliberately nonfunctional; all traffic is
  # anonymous READ traffic. Each row belongs only to the throwaway DB.
  compose exec -T -e MUCHO_SYNTHETIC_SCALE_RUN=1 app \
    php tools/performance/seed_stress_four_scale.php "--size=$size" \
    | tee "$dir/fixture.json"

  # Avoid serving an old public listing cached under the previous scale size.
  # Normal cache invalidation still applies; no cache backend is disabled.
  sleep 16

  {
    date -u
    free -m
    compose ps
  } > "$dir/before.txt"

  (
    while :; do
      date -u +%Y-%m-%dT%H:%M:%SZ
      docker stats --no-stream --format '{{.Name}}|{{.MemUsage}}|{{.CPUPerc}}' \
        muchocore-stress-app-1 muchocore-stress-db-1 muchocore-stress-caddy-1 || true
      sleep 5
    done
  ) > "$dir/resources.log" 2>&1 &
  sampler_pid="$!"

  # 60s, one client, 450ms intervals. GD protocol checks must be meaningful.
  python3 tools/performance/stress_scale.py \
    --levels "$size" --accounts "$size" --duration 60 \
    --output "$dir/report.json" | tee "$dir/benchmark.log"

  cleanup_sampler

  {
    date -u
    free -m
    compose ps
    for service in app db caddy; do
      id="$(compose ps -q "$service")"
      if [[ -z "$id" ]]; then
        echo "CRITICAL: missing stress service $service"
        exit 1
      fi
      docker inspect --format '{{.Name}}|oom={{.State.OOMKilled}}|restarts={{.RestartCount}}' "$id"
      oom="$(docker inspect -f '{{.State.OOMKilled}}' "$id")"
      restarts="$(docker inspect -f '{{.RestartCount}}' "$id")"
      if [[ "$oom" != false || "$restarts" != 0 ]]; then
        echo "CRITICAL: $service restarted or ran out of memory"
        exit 1
      fi
    done
  } > "$dir/after.txt"

  test "$(curl -fsS --max-time 5 http://127.0.0.1:18080/health)" = 1
  echo "STAGE_PASS=$size"
done

python3 tools/performance/summarize_four_scale.py \
  --report-dir "$report_root" --output "$report_root/summary.md"
cat "$report_root/summary.md"
cat "$report_root/summary.md" >> "$GITHUB_STEP_SUMMARY"
echo "MUCHOCORE_FOUR_SCALE_STRESS_PASS"
