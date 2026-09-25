#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
SERVICE="/etc/systemd/system/muchocore-auto-update.service"
TIMER="/etc/systemd/system/muchocore-auto-update.timer"

[[ $EUID -eq 0 ]] || {
  echo '[MuchoCore] Run this script as root.' >&2
  exit 1
}

AUTO_UPDATE=1
INTERVAL=15min

if [[ -f "$ENV_FILE" ]]; then
  AUTO_UPDATE="$(sed -n 's/^MUCHO_AUTO_UPDATE=//p' "$ENV_FILE" | head -n1 || true)"
  INTERVAL="$(sed -n 's/^MUCHO_AUTO_UPDATE_INTERVAL=//p' "$ENV_FILE" | head -n1 || true)"
fi

AUTO_UPDATE="${AUTO_UPDATE:-1}"
INTERVAL="${INTERVAL:-15min}"

case "${AUTO_UPDATE,,}" in
  1|true|yes|on) ENABLED=1 ;;
  0|false|no|off) ENABLED=0 ;;
  *)
    echo "[MuchoCore] Invalid MUCHO_AUTO_UPDATE='$AUTO_UPDATE'." >&2
    exit 1
    ;;
esac

[[ "$INTERVAL" =~ ^[0-9]+(s|sec|secs|min|mins|h|hr|hrs|d|day|days)$ ]] || {
  echo "[MuchoCore] Invalid MUCHO_AUTO_UPDATE_INTERVAL='$INTERVAL'." >&2
  echo '[MuchoCore] Use values such as 15min, 30min, 1h or 1d.' >&2
  exit 1
}

if [[ "$ENABLED" -eq 0 ]]; then
  systemctl disable --now muchocore-auto-update.timer >/dev/null 2>&1 || true
  rm -f "$SERVICE" "$TIMER"
  systemctl daemon-reload
  echo '[MuchoCore] Automatic updates disabled.'
  exit 0
fi

cat > "$SERVICE" <<EOFSERVICE
[Unit]
Description=MuchoCore automatic updater
Wants=network-online.target
After=network-online.target docker.service
Requires=docker.service

[Service]
Type=oneshot
User=root
WorkingDirectory=$ROOT
ExecStart=/bin/bash $ROOT/auto-update.sh
TimeoutStartSec=30min
PrivateTmp=yes
ProtectHome=yes
NoNewPrivileges=yes
EOFSERVICE

cat > "$TIMER" <<EOFTIMER
[Unit]
Description=Run MuchoCore automatic update checks

[Timer]
OnBootSec=5min
OnUnitActiveSec=$INTERVAL
RandomizedDelaySec=60s
Persistent=true
Unit=muchocore-auto-update.service

[Install]
WantedBy=timers.target
EOFTIMER

chmod 755 "$ROOT/auto-update.sh" "$ROOT/bin/mucho-install-auto-update.sh"
systemctl daemon-reload
systemctl enable --now muchocore-auto-update.timer

echo "[MuchoCore] Automatic updates enabled: every $INTERVAL."
echo '[MuchoCore] Timer: systemctl status muchocore-auto-update.timer'
echo '[MuchoCore] Log:   /var/log/muchocore/auto-update.log'
