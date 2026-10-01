#!/usr/bin/env bash
set -Eeuo pipefail
set -x

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
KIT="$ROOT/tools/migration/mucho-migrate.sh"

check_file() {
    local pattern="$1"
    if ! grep -Fq "$pattern" "$KIT"; then
        echo "MIGRATION_KIT_MISSING=$pattern" >&2
        exit 1
    fi
}

echo "MIGRATION_KIT_TEST_ROOT=$ROOT"
test -f "$KIT"
echo "MIGRATION_KIT_FILE_OK"
bash -n "$KIT"
echo "MIGRATION_KIT_SYNTAX_OK"

echo "MIGRATION_KIT_RUNNING_HELP"
set +e
help_output="$(bash "$KIT" --help 2>&1)"
help_status=$?
set -e
if (( help_status != 0 )); then
    echo "MIGRATION_KIT_HELP_EXIT=$help_status" >&2
    echo "$help_output" >&2
    exit "$help_status"
fi
check_help() {
    local pattern="$1"
    if ! grep -Fq -- "$pattern" <<<"$help_output"; then
        echo "MIGRATION_KIT_HELP_MISSING=$pattern" >&2
        echo "$help_output" >&2
        exit 1
    fi
}

echo "MIGRATION_KIT_HELP_OK"
check_help "MuchoCore Migration Kit"
check_help "--source-host=HOST"
check_help "--apply"
check_help "--confirm=COVOLTON"

check_file "TARGET_BACKUP="
check_file "CVOLTON_SOURCE_PASS"
check_file "mucho-healthcheck.php"
check_file "bin/import-cvolton-db.php"

echo "MIGRATION_KIT_OK"
