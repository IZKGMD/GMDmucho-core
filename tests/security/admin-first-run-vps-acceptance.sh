#!/usr/bin/env bash
# Isolated MuchoCore stress-stack acceptance. Root bridge/operator only.
# Never touches production /opt/mucho-core or the stress application's GDPS DB.
set -Eeuo pipefail

[[ "${MUCHO_ADMIN_FIRST_RUN_STRESS:-}" == "1" ]] || {
  echo "Refusing: set MUCHO_ADMIN_FIRST_RUN_STRESS=1 for isolated acceptance." >&2
  exit 2
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ "$root" == /opt/mucho-stress/.qa-adminfirst-* ]] || {
  echo "Refusing: test must run from an isolated stress checkout." >&2
  exit 2
}

database='mucho_adminfirst_test_228'
db_container='muchocore-stress-db-1'
app_container='muchocore-stress-app-1'
created=0

cleanup() {
  if [[ "$created" == "1" ]]; then
    docker exec -i "$db_container" sh -c \
      'exec mariadb --user=root --password="$(cat /run/secrets/stress_db_root_password)"' >/dev/null <<'SQL' || true
REVOKE ALL PRIVILEGES ON mucho_adminfirst_test_228.* FROM 'mucho_stress'@'%';
DROP DATABASE IF EXISTS mucho_adminfirst_test_228;
SQL
  fi
}
trap cleanup EXIT

# CREATE without IF NOT EXISTS ensures an existing database is never reused.
docker exec -i "$db_container" sh -c \
  'exec mariadb --user=root --password="$(cat /run/secrets/stress_db_root_password)"' <<'SQL'
CREATE DATABASE mucho_adminfirst_test_228;
SQL
created=1

docker exec -i "$db_container" sh -c \
  'exec mariadb --user=root --password="$(cat /run/secrets/stress_db_root_password)"' <<'SQL'
GRANT ALL PRIVILEGES ON mucho_adminfirst_test_228.* TO 'mucho_stress'@'%';
SQL

docker exec \
  -e MUCHO_ALLOW_ADMIN_SETUP_TEST=1 \
  -e MUCHO_ADMIN_SETUP_TEST_DB="$database" \
  "$app_container" \
  php "/var/www/mucho-core/${root##*/}/tests/security/admin-first-run-live.php"

echo "ISOLATED_ADMIN_SETUP_ACCEPTANCE_OK"
