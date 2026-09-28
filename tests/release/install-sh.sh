#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

test -f install.sh
test -f install

bash -n install.sh
bash -n install

grep -Fq 'flock -n 9' install.sh
grep -Fq 'DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl git jq openssl' install.sh
grep -Fq "jq -r '.tag_name // empty'" install.sh
grep -Fq 'git -C "$INSTALL_DIR" diff --quiet' install.sh
grep -Fq 'git -C "$INSTALL_DIR" diff --cached --quiet' install.sh
grep -Fq 'DOMAIN="${DOMAIN#http://}"' install.sh
grep -Fq 'DOMAIN="${DOMAIN#https://}"' install.sh

grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/db_password" ]]; then' install.sh
grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/db_root_password" ]]; then' install.sh
grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/admin_password" ]]; then' install.sh
grep -Fq 'if [[ -s "$INSTALL_DIR/.secrets/cloudsave_key" ]]; then' install.sh
grep -Fq 'Required secret file is missing or empty' install.sh

grep -Fq 'expected_services=(db app worker caddy testgdps-db testgdps-app)' install.sh
grep -Fq 'php bin/migrate.php migrate' install.sh
grep -Fq 'php bin/mucho-healthcheck.php' install.sh
grep -Fq 'https://$DOMAIN/health' install.sh

migration_line="$(grep -n 'php bin/migrate.php migrate' install.sh | head -n1 | cut -d: -f1)"
health_line="$(grep -n 'log \"Checking server health...\"' install.sh | head -n1 | cut -d: -f1)"
internal_health_line="$(grep -n 'php bin/mucho-healthcheck.php' install.sh | head -n1 | cut -d: -f1)"

[[ "$migration_line" -lt "$health_line" ]]
[[ "$internal_health_line" -lt "$health_line" ]]

if grep -q '^[[:space:]]*command -v curl' install.sh; then
    echo 'install-sh: curl must be installed before it is required by preflight' >&2
    exit 1
fi

if grep -q '^[[:space:]]*command -v git' install.sh; then
    echo 'install-sh: git must be installed before it is required by preflight' >&2
    exit 1
fi

if command -v shellcheck >/dev/null 2>&1; then
    shellcheck install.sh install
fi

echo "install-sh: OK"