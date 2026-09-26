#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

bash -n install
bash -n install.sh
bash -n update.sh
bash -n docker/app-entrypoint.sh

if grep -Fq 'docker compose "\${COMPOSE_ARGS[@]}"' update.sh; then
  echo 'release-deployment-tools: escaped Compose array expansion detected' >&2
  exit 1
fi

grep -Fq 'docker compose "${COMPOSE_ARGS[@]}"' update.sh
grep -Fq 'CADDY_EXTRA_HOSTS="${MUCHO_CADDY_EXTRA_HOSTS:-testgdps.muchogdps.space}"' install.sh
grep -Fq 'CADDY_EXTRA_HOSTS=$CADDY_EXTRA_HOSTS' install.sh
grep -Fq 'DB_HOST=${DB_HOST:-db}' docker/app-entrypoint.sh

echo "release-deployment-tools: PASS"
