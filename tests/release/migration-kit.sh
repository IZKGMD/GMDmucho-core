#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
KIT="$ROOT/tools/migration/mucho-migrate.sh"

test -f "$KIT"
bash -n "$KIT"

help_output="$(bash "$KIT" --help)"
grep -Fq "MuchoCore Migration Kit" <<<"$help_output"
grep -Fq -- "--source-host=HOST" <<<"$help_output"
grep -Fq -- "--apply" <<<"$help_output"
grep -Fq -- "--confirm=COVOLTON" <<<"$help_output"

grep -Fq "TARGET_BACKUP=" "$KIT"
grep -Fq "CVOLTON_SOURCE_PASS" "$KIT"
grep -Fq "mucho-healthcheck.php" "$KIT"
grep -Fq "bin/import-cvolton-db.php" "$KIT"

echo "MIGRATION_KIT_OK"
