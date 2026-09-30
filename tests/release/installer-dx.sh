#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

bash -n install.sh
bash -n install-remote.sh
bash -n bin/mucho

grep -Fq -- '--quick' install.sh
grep -Fq -- '--domain=HOST' install.sh
grep -Fq -- '--gd-versions=PROFILE' install.sh
grep -Fq -- '--migrate' install.sh
grep -Fq 'curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install-remote.sh | sudo bash' install.sh
grep -Fq 'check_domain_preflight' install.sh
grep -Fq 'sudo mucho doctor' install.sh
! grep -Fq 'expected_services=(db app worker caddy testgdps-db testgdps-app)' install.sh
! grep -Fq 'testgdps' docker-compose.yml

for command in status logs restart doctor repair update backup migrate migration config test-stack install test; do
    grep -Fq "\${command})" bin/mucho || {
        echo "Missing Mucho CLI command: \${command}" >&2
        exit 1
    }
done

grep -Fq 'docker-compose.test.yml' bin/mucho
grep -Fq 'The production stack never starts the integration test tenant automatically.' bin/mucho

echo "installer-dx: OK"
