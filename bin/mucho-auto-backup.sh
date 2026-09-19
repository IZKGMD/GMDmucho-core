#!/usr/bin/env bash
set -euo pipefail

ROOT="/var/www/mucho-core"
LOG="$ROOT/logs/automatic-backup.log"

mkdir -p "$ROOT/logs" "$ROOT/backups"

{
    echo "===== $(date -Is) ====="

    if [ -x /usr/local/sbin/mucho-admin-ops ]; then
        /usr/local/sbin/mucho-admin-ops backup-db
        /usr/local/sbin/mucho-admin-ops backup-code
    else
        echo "mucho-admin-ops not found"
        exit 1
    fi

    find "$ROOT/backups" \
        -type f \
        -mtime +14 \
        -delete

    echo "BACKUP_OK"

} >> "$LOG" 2>&1
