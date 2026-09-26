#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/.env"
enabled=1
interval=15min

if [[ -f "$ENV_FILE" ]]; then
    value="$(sed -n "s/^MUCHO_AUTO_UPDATE=//p" "$ENV_FILE" | head -n1 || true)"
    [[ "$value" == "0" ]] && enabled=0
    value="$(sed -n "s/^MUCHO_AUTO_UPDATE_INTERVAL=//p" "$ENV_FILE" | head -n1 || true)"
    [[ -n "$value" ]] && interval="$value"
fi

if ! command -v systemctl >/dev/null 2>&1; then
    exit 0
fi

cat > /etc/systemd/system/muchocore-auto-update.service <<EOF_SERVICE
[Unit]
Description=MuchoCore published-release automatic updater
After=docker.service network-online.target
Wants=docker.service network-online.target

[Service]
Type=oneshot
WorkingDirectory=$ROOT
ExecStart=/usr/bin/bash $ROOT/auto-update.sh
EOF_SERVICE

cat > /etc/systemd/system/muchocore-auto-update.timer <<EOF_TIMER
[Unit]
Description=Check for new MuchoCore stable releases

[Timer]
OnBootSec=3min
OnUnitActiveSec=$interval
Persistent=true
Unit=muchocore-auto-update.service

[Install]
WantedBy=timers.target
EOF_TIMER

systemctl daemon-reload

if ((enabled)); then
    systemctl enable --now muchocore-auto-update.timer
else
    systemctl disable --now muchocore-auto-update.timer >/dev/null 2>&1 || true
fi

echo "[MuchoCore] Published-release auto-update timer: $([[ $enabled -eq 1 ]] && echo enabled || echo disabled), interval=$interval"