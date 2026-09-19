#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
[[ $EUID -eq 0 ]] || { echo 'Запустите: sudo ./uninstall.sh' >&2; exit 1; }
cd "$ROOT"

echo "ВНИМАНИЕ: будут удалены контейнеры и volume базы MuchoCore."
read -r -p 'Для подтверждения введите DELETE: ' answer
[[ "$answer" == "DELETE" ]] || exit 0

docker compose down -v --remove-orphans
rm -rf "$ROOT"
echo 'MuchoCore удалён.'
