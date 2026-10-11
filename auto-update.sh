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

# update.sh selects stable tags or origin/main according to the installation
# channel, and resumes a pending deployment even when its source is current.
echo "[MuchoCore] Checking for core updates..."
"$ROOT/update.sh"
