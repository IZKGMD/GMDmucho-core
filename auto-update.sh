#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

[[ $EUID -eq 0 ]] || {
  echo '[MuchoCore] Run the auto-updater as root.' >&2
  exit 1
}

LOG_DIR="/var/log/muchocore"
LOG_FILE="$LOG_DIR/auto-update.log"
mkdir -p "$LOG_DIR"
chmod 750 "$LOG_DIR"

log() {
  printf '[%s] %s\n' "$(date -Is)" "$*" | tee -a "$LOG_FILE"
}

exec 9>/run/muchocore-auto-update.lock
if ! flock -n 9; then
  log 'Another automatic update check is already running; skipping.'
  exit 0
fi

AUTO_UPDATE="$(sed -n 's/^MUCHO_AUTO_UPDATE=//p' "$ROOT/.env" 2>/dev/null | head -n1 || true)"
AUTO_UPDATE="${AUTO_UPDATE:-1}"

case "${AUTO_UPDATE,,}" in
  1|true|yes|on) ;;
  0|false|no|off)
    log 'Automatic updates are disabled (MUCHO_AUTO_UPDATE=0).'
    exit 0
    ;;
  *)
    log "Invalid MUCHO_AUTO_UPDATE='$AUTO_UPDATE'; automatic update skipped."
    exit 1
    ;;
esac

if ! git diff --quiet || ! git diff --cached --quiet; then
  log 'Tracked local changes detected; refusing to auto-update.'
  exit 1
fi

if ! git remote get-url origin >/dev/null 2>&1; then
  log 'Git origin is not configured; refusing to auto-update.'
  exit 1
fi

log 'Checking origin/main for a new MuchoCore version...'
if ! git fetch --depth=1 origin main >>"$LOG_FILE" 2>&1; then
  log 'Unable to fetch origin/main; keeping the current version.'
  exit 1
fi

CURRENT="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse origin/main)"

if [[ "$CURRENT" == "$REMOTE" ]]; then
  log "Already up to date ($CURRENT)."
  exit 0
fi

log "Update available: $CURRENT -> $REMOTE"
log 'Handing the update to update.sh...'

if "$ROOT/update.sh" >>"$LOG_FILE" 2>&1; then
  log "Automatic update completed successfully. Current commit: $(git rev-parse HEAD)"
  exit 0
fi

log 'Automatic update failed. See update.sh output above.'
exit 1
