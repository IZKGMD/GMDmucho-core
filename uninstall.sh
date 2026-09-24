#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./uninstall.sh' >&2; exit 1; }
cd "$ROOT"

echo "WARNING: MuchoCore containers and the database volume will be removed."
read -r -p 'Type DELETE to confirm: ' answer
[[ "$answer" == "DELETE" ]] || exit 0

docker compose down -v --remove-orphans
rm -rf "$ROOT"
echo 'MuchoCore has been removed.'
