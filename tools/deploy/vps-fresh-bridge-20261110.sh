#!/usr/bin/env bash
# MuchoCore fresh PHP8 deployment via pre-authorized GitHub issue #205 VPS bridge.
# TEST VPS ONLY: no system wipe, no host volumes removed, no credentials logged.
set -Eeuo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

B=/root/muchocore-before-fresh-20261110
ROOT=/opt/mucho-core
OLD=/opt/mucho-core.before-fresh-20261110
CLEAN=/opt/mucho-clean-228
TARGET=d8cdf5026f36c30d072f85cf4c50974f9e2fc757
PROJECT=muchocore-fresh-20261110
LOG="$B/deploy.log"
switched=0
affected=0

exec >>"$LOG" 2>&1
echo "FRESH_INSTALL_BEGIN $(date -u +%FT%TZ)"

rollback() {
  rc=$?
  trap - EXIT
  if ((rc == 0)); then
    echo "FRESH_INSTALL_SUCCESS"
    exit 0
  fi
  echo "FRESH_INSTALL_FAILED exit=$rc"
  if ((affected == 1)); then
    if ((switched == 1)); then
      if [[ -f "$ROOT/docker-compose.yml" ]]; then
        (cd "$ROOT"; COMPOSE_PROJECT_NAME="$PROJECT" docker compose down --remove-orphans) || true
      fi
      if [[ -d "$ROOT" && ! -e "$B/failed-new-install" ]]; then
        mv "$ROOT" "$B/failed-new-install" || true
      fi
      if [[ -d "$OLD" && ! -d "$ROOT" ]]; then
        mv "$OLD" "$ROOT" || true
      fi
    fi
    echo "ROLLBACK_RESTORING_GATEWAY"
    if [[ -f "$CLEAN/compose.clean.yml" ]]; then
      (cd "$CLEAN"; docker compose -f compose.clean.yml up -d --no-build) || true
    fi
    if [[ -f "$ROOT/compose.public.yaml" ]]; then
      (cd "$ROOT"; docker compose -f docker-compose.yml -f compose.public.yaml up -d --no-build caddy) || true
    fi
    echo "ROLLBACK_ATTEMPTED"
  fi
  exit "$rc"
}
trap rollback EXIT

test -d "$B"
test -s "$B/old-installations.tar.gz"
test -s "$B/clean-228-database.sql.gz"
(cd "$B"; sha256sum -c SHA256SUMS)
test -d "$ROOT/.git" && test -f "$ROOT/compose.public.yaml"
test -f "$CLEAN/compose.clean.yml"
test ! -e "$OLD"
test ! -e "$B/failed-new-install"
test "$(stat -c %a "$B")" = 700
test -n "$(docker ps -q -f name=^/mucho-core-caddy-1$)"
command -v systemctl >/dev/null
command -v curl >/dev/null
command -v openssl >/dev/null
echo "BACKUPS_VERIFIED"

# Keep the old Compose projects and their volumes intact for rollback.
affected=1
(cd "$ROOT"; docker compose -f docker-compose.yml -f compose.public.yaml down --remove-orphans)
(cd "$CLEAN"; docker compose -f compose.clean.yml down --remove-orphans)
if [[ -f /opt/mucho-stress/docker-compose.stress.yml ]]; then
  (cd /opt/mucho-stress; docker compose -f docker-compose.stress.yml down --remove-orphans)
fi
docker stop muchocore-acceptance-227 >/dev/null 2>&1 || true
echo "OLD_APPS_STOPPED"

mv "$ROOT" "$OLD"
switched=1
echo "OLD_INSTALL_MOVED"

# Isolate the freshly created database, app runtime and Caddy TLS volumes.
# No older Docker volume will be deleted or reused by the new project.
export COMPOSE_PROJECT_NAME="$PROJECT"
export MUCHO_TRANSPORT_MODE=direct
export MUCHO_ADMIN_PASSWORD
MUCHO_ADMIN_PASSWORD="$(openssl rand -hex 32)"
curl -fsSL --retry 3 --connect-timeout 10 --max-time 35 \
  "https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/$TARGET/install.sh" \
  -o "$B/pinned-installer.sh"
bash -n "$B/pinned-installer.sh"
echo "INSTALLER_READY"
bash "$B/pinned-installer.sh" --ref=main --quick \
  --domain=muchogdps.space --server-name=MuchoGDPS --gd-versions=all
unset MUCHO_ADMIN_PASSWORD
test "$(git -C "$ROOT" rev-parse HEAD)" = "$TARGET"
echo "PINNED_MAIN_INSTALLED"

# Present the canonical project portal on the intended public domain.
sed -i 's/^MUCHOCORE_SITE_HOST=.*/MUCHOCORE_SITE_HOST=muchogdps.space/' "$ROOT/.env"
sed -i 's/^MUCHO_AUTO_UPDATE=.*/MUCHO_AUTO_UPDATE=0/' "$ROOT/.env"
grep -q '^MUCHOCORE_SITE_HOST=muchogdps.space$' "$ROOT/.env"
if ! grep -q '^COMPOSE_PROJECT_NAME=' "$ROOT/.env"; then
  printf '\nCOMPOSE_PROJECT_NAME=%s\n' "$PROJECT" >> "$ROOT/.env"
fi
chmod 600 "$ROOT/.env"
systemctl disable --now muchocore-auto-update.timer >/dev/null 2>&1 || true
(cd "$ROOT"; docker compose up -d --no-build --force-recreate app caddy)
echo "FINAL_CONFIG_APPLIED"

healthy=0
for attempt in $(seq 1 40); do
  code="$(curl -sS -m 6 --noproxy '*' \
     --resolve muchogdps.space:443:127.0.0.1 \
     -o /tmp/muchocore-fresh-health -w '%{http_code}' \
     https://muchogdps.space/health 2>/dev/null || true)"
  if [[ "$code" == 200 ]] && grep -qx '1' /tmp/muchocore-fresh-health; then
    healthy=1
    break
  fi
  sleep 3
done
rm -f /tmp/muchocore-fresh-health
test "$healthy" = 1
echo "HTTPS_HEALTH_PASS"

for path in /admin/ /dashboard/ /muchocore/ /database/getGJLevels21.php /database/getGJSongInfo.php; do
  code="$(curl -sS -m 10 --noproxy '*' --resolve muchogdps.space:443:127.0.0.1 \
     -o /dev/null -w '%{http_code}' "https://muchogdps.space$path")"
  echo "HTTP_CHECK $path $code"
  test "$code" = 200
done
(cd "$ROOT"; docker compose exec -T app php bin/mucho-healthcheck.php >/dev/null)
echo "MUCHOCORE_FRESH_VPS_READY"
echo "BACKUP_LOCATION=$B"
echo "OLD_DB_VOLUMES_RETAINED"
