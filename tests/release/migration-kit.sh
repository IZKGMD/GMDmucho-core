#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
KIT="$ROOT/tools/migration/mucho-migrate.sh"

check_file() {
    local pattern="$1"
    if ! grep -Fq "$pattern" "$KIT"; then
        echo "MIGRATION_KIT_MISSING=$pattern" >&2
        exit 1
    fi
}

test -f "$KIT"
bash -n "$KIT"

help_output="$(bash "$KIT" --help 2>&1)"
check_help() {
    local pattern="$1"
    if ! grep -Fq -- "$pattern" <<<"$help_output"; then
        echo "MIGRATION_KIT_HELP_MISSING=$pattern" >&2
        echo "$help_output" >&2
        exit 1
    fi
}

check_help "MuchoCore Migration Kit"
check_help "--source-host=HOST"
check_help "--apply"
check_help "--confirm=COVOLTON"

check_file "TARGET_BACKUP="
check_file "CVOLTON_SOURCE_PASS"
check_file "mucho-healthcheck.php"
check_file "bin/import-cvolton-db.php"

echo "MIGRATION_KIT_OK"
