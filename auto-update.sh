#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

[[ $EUID -eq 0 ]] || {
    echo '[MuchoCore] Run automatic updates as root.' >&2
    exit 1
}

if [[ -f "$ROOT/.env" ]] && grep -q "^MUCHO_AUTO_UPDATE=0$" "$ROOT/.env"; then
    exit 0
fi

# update.sh is the single deployment path. It checks GitHub Releases and
# refuses to deploy ordinary main-branch commits or downgrade the server.
echo "[MuchoCore] Checking for a published stable release..."
"$ROOT/update.sh"
