#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

test -f install.sh
test -f install

bash -n install.sh
bash -n install
bash -n bin/mucho-admin-setup.sh
grep -Fq 'Setup secret / explicit reset password (at least 10 characters):' bin/mucho-admin-setup.sh
grep -Fq 'First-time MuchoCore setup. Choose a setup secret for the /admin/ browser wizard.' bin/mucho-admin-setup.sh
grep -Fq 'Confirm admin password:' bin/mucho-admin-setup.sh
grep -Fq 'docker compose "${compose[@]}" up -d --no-deps --no-build --force-recreate app' bin/mucho-admin-setup.sh
grep -Fq 'php bin/mucho-sync-admin.php --reset-password' bin/mucho-admin-setup.sh
! grep -Fq 'echo "$first"' bin/mucho-admin-setup.sh
bash -n tools/release/build-shared-hosting.sh
test -f public/shared-install.php
grep -Fq '20260925_000_admin_users.php' < <(git ls-files database/migrations/20260925_000_admin_users.php)
php -l public/shared-install.php >/dev/null
grep -Fq 'shared-install.installed' public/shared-install.php
grep -Fq 'databasePreflight(' public/shared-install.php
grep -Fq "dotenvLine('MUCHO_SHARED_HOSTING', '1')" public/shared-install.php
grep -Fq "dotenvLine('MUCHO_DB_BACKUP_DIR'" public/shared-install.php

grep -Fq 'flock -n 9' install.sh
grep -Fq 'DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl git jq openssl' install.sh
grep -Fq "jq -r '.tag_name // empty'" install.sh
grep -Fq 'git -C "$INSTALL_DIR" diff --quiet' install.sh
grep -Fq 'git -C "$INSTALL_DIR" diff --cached --quiet' install.sh
grep -Fq 'value="${value#http://}"' install.sh
grep -Fq 'value="${value#https://}"' install.sh

grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/db_password" ]]; then' install.sh
grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/db_root_password" ]]; then' install.sh
# New interactive installs collect the first owner before the GDPS name/domain.
# Legacy interrupted browser setup remains recoverable, but there is no new
# automatically generated browser activation key.
grep -Fq 'collect_first_owner_credentials()' install.sh
grep -Fq 'collect_first_owner_credentials' install.sh
grep -Fq 'Step 1 — Create your first administrator' install.sh
grep -Fq 'Admin login [admin]:' install.sh
grep -Fq 'Password (12–256 characters):' install.sh
grep -Fq 'Confirm password:' install.sh
grep -Fq 'if [[ "$first" != "$confirm" ]]' install.sh
grep -Fq 'OWNER_BROWSER_PENDING=' install.sh
grep -Fq 'sudo mucho admin' install.sh
grep -Fq 'private MUCHO_ADMIN_PASSWORD for unattended installs' install.sh
! grep -Fq 'MUCHO_ADMIN_PASSWORD="$(openssl rand -hex 32)"' install.sh
! grep -Fq 'touch "$OWNER_BROWSER_PENDING"' install.sh
grep -Fq 'owner-initialize-pending' install.sh
grep -Fq 'if [[ -s "$OWNER_PENDING" ]]; then' install.sh
grep -Fq 'php bin/mucho-initialize-owner.php --initialize' install.sh
grep -Fq 'Existing administrator secret kept; no password change requested.' install.sh

first_owner_line="$(grep -n '^collect_first_owner_credentials$' install.sh | head -n1 | cut -d: -f1)"
gdps_name_line="$(grep -nF 'if [[ -z "$SERVER_NAME" && -e /dev/tty ]]; then' install.sh | head -n1 | cut -d: -f1)"
domain_input_line="$(grep -nF 'read -r -p "  Domain: "' install.sh | head -n1 | cut -d: -f1)"
[[ -n "$first_owner_line" && -n "$gdps_name_line" && -n "$domain_input_line" ]]
[[ "$first_owner_line" -lt "$gdps_name_line" ]]
[[ "$first_owner_line" -lt "$domain_input_line" ]]
test -f bin/mucho-initialize-owner.php
php -l bin/mucho-initialize-owner.php >/dev/null
grep -Fq 'FirstRunSetup::createOwner' bin/mucho-initialize-owner.php
grep -Fq "'--initialize'" bin/mucho-initialize-owner.php
grep -Fq 'Owner already initialized; existing administrator credentials preserved.' bin/mucho-initialize-owner.php
test -f bin/mucho-owner-recovery-link.php
php -l bin/mucho-owner-recovery-link.php >/dev/null
grep -Fq "password_setup_expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR)" bin/mucho-owner-recovery-link.php

grep -Fq 'Admin password must contain at least 10 characters.' bin/mucho
grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/cloudsave_key" ]]; then' install.sh
grep -Fq 'Required secret file is missing or empty' install.sh

grep -Fq 'expected_services=(db app worker caddy)' install.sh
! grep -Fq 'testgdps' install.sh
! grep -Fq 'testgdps' docker-compose.yml
grep -Fq 'php bin/migrate.php migrate' install.sh
grep -Fq 'php bin/mucho-healthcheck.php' install.sh
grep -Fq 'https://$DOMAIN/health' install.sh

grep -Fq 'docker compose -f docker-compose.yml "$@"' install.sh
grep -Fq 'docker compose -f docker-compose.yml -f docker-compose.tunnel.yml "$@"' install.sh
grep -Fq 'Compose files verified: $INSTALL_DIR/docker-compose.yml' install.sh
grep -Fq 'confirm_args+=(--confirm=MIGRATE)' install.sh

grep -Fq 'offer_database_migration()' install.sh
grep -Fq 'MUCHO_MIGRATION_ON_INSTALL' install.sh
grep -Fq -- '--migrate' install.sh
grep -Fq -- '--domain=HOST' install.sh
grep -Fq -- '--gd-versions=PROFILE' install.sh
grep -Fq 'sudo mucho doctor' install.sh
grep -Fq 'Do you want to migrate an existing GDPS database now?' install.sh

migration_line="$(grep -n 'php bin/migrate.php migrate' install.sh | head -n1 | cut -d: -f1)"
health_line="$(grep -n 'log \"Checking server health...\"' install.sh | head -n1 | cut -d: -f1)"
internal_health_line="$(grep -n 'php bin/mucho-healthcheck.php' install.sh | head -n1 | cut -d: -f1)"

[[ "$migration_line" -lt "$health_line" ]]
[[ "$internal_health_line" -lt "$health_line" ]]
[[ "$(grep -c 'php bin/migrate.php migrate' install.sh)" -eq 1 ]]

if grep -q '^[[:space:]]*command -v curl' install.sh; then
    echo 'install-sh: curl must be installed before it is required by preflight' >&2
    exit 1
fi

if grep -q '^[[:space:]]*command -v git' install.sh; then
    echo 'install-sh: git must be installed before it is required by preflight' >&2
    exit 1
fi

grep -Fq 'Installation failed during: $INSTALL_STEP' install.sh
grep -Fq 'check_domain_preflight' install.sh
grep -Fq 'INSTALL_STEP="detecting public network"' install.sh
grep -Fq 'apt-get install -y ca-certificates curl git jq openssl' install.sh

echo "install-sh: OK"
