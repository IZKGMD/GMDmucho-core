#!/usr/bin/env bash
# Finishes a validated blue/green PHP8 installation on the disposable VPS.
# Keeps the old checkout and every old Docker volume for rollback.
set -Eeuo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

readonly BACKUP=/root/muchocore-before-fresh-20261110
readonly NEW=/opt/mucho-stage-20261111
readonly LIVE=/opt/mucho-core
readonly OLD=/opt/mucho-core.legacy-20261111
readonly SHA=d8cdf5026f36c30d072f85cf4c50974f9e2fc757
moved=0

exec >>"$BACKUP/canonical-cutover.log" 2>&1
echo "CANONICAL_SWITCH_BEGIN $(date -u +%FT%TZ)"

rollback() {
  result=$?
  trap - ERR EXIT
  set +e
  echo "CANONICAL_SWITCH_FAILED exit=$result"
  if ((moved)); then
    (cd "$LIVE" && docker compose stop caddy app worker) || true
    if [[ -d "$LIVE" && ! -e "$NEW" ]]; then mv "$LIVE" "$NEW"; fi
    if [[ -d "$OLD" && ! -e "$LIVE" ]]; then mv "$OLD" "$LIVE"; fi
    (cd "$LIVE" && docker compose -f docker-compose.yml -f compose.public.yaml up -d --no-build caddy) || true
    echo "CANONICAL_ROLLBACK_TO_OLD_GATEWAY_ATTEMPTED"
  else
    (cd "$NEW" && docker compose up -d --no-build --no-deps app worker caddy) || true
    echo "CANONICAL_ROLLBACK_TO_STAGE_ATTEMPTED"
  fi
  exit "$result"
}
trap rollback ERR

test -d "$BACKUP" && test -s "$BACKUP/old-installations.tar.gz"
test ! -e "$OLD"
test -d "$NEW/.git"
test -f "$LIVE/compose.public.yaml"
test "$(git -C "$NEW" rev-parse HEAD)" = "$SHA"
test "$(grep -c '^COMPOSE_PROJECT_NAME=muchocore-next-20261111$' "$NEW/.env")" = 1
test "$(curl -fsS -m 8 --noproxy '*' --resolve muchogdps.space:443:127.0.0.1 https://muchogdps.space/health)" = 1
echo "CANONICAL_PRECHECK_PASS"

(cd "$NEW"; docker compose stop caddy app worker)
mv "$LIVE" "$OLD"
mv "$NEW" "$LIVE"
moved=1
echo "CANONICAL_PATH_MOVED"

(cd "$LIVE"; docker compose up -d --no-build --force-recreate --no-deps app worker caddy)
echo "CANONICAL_CONTAINERS_RECREATED"

ok=0
for n in $(seq 1 20); do
  response="$(curl -fsS -m 7 --noproxy '*' \
    --resolve muchogdps.space:443:127.0.0.1 \
    https://muchogdps.space/health 2>/dev/null || true)"
  if [[ "$response" == 1 ]]; then ok=1; break; fi
  sleep 2
done
test "$ok" = 1
echo "CANONICAL_HTTPS_HEALTH_PASS"

for path in /admin/ /dashboard/ /muchocore/ /database/getGJLevels21.php /database/getGJSongInfo.php; do
  code="$(curl -sS -m 9 --noproxy '*' \
    --resolve muchogdps.space:443:127.0.0.1 \
    -o /dev/null -w '%{http_code}' "https://muchogdps.space$path")"
  echo "CANONICAL_HTTP $path $code"
  test "$code" = 200
done
code="$(curl -sS -m 7 --noproxy '*' \
  --resolve muchogdps.space:80:127.0.0.1 \
  -o /dev/null -w '%{http_code}' 'http://muchogdps.space/database/getGJLevels21.php')"
echo "CANONICAL_LEGACY_HTTP $code"
test "$code" = 200
(cd "$LIVE"; docker compose exec -T app php bin/mucho-healthcheck.php)
echo "CANONICAL_PHP_HEALTH_PASS"

trap - ERR
echo "CANONICAL_SWITCH_SUCCESS"
echo "OLD_CHECKOUT_RETAINED=$OLD"
