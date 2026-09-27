#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MODE="dry-run"
SOURCE_HOST=""
SOURCE_PORT="3306"
SOURCE_DB=""
SOURCE_USER=""
SOURCE_PASS="$(printenv CVOLTON_SOURCE_PASS 2>/dev/null || true)"
CONFIRM=""
REPORT_DIR="$ROOT/backups/migrations"
TIMESTAMP="$(date -u +%Y%m%d_%H%M%S)"
REPORT_FILE="$REPORT_DIR/migration_"$TIMESTAMP".log"

usage() {
    cat <<'TXT'
MuchoCore Migration Kit

Safe Cvolton/GMDprivateServer-style database migration wrapper.

Default: read-only preflight / dry-run. No destination data is modified.

Usage:
  sudo ./tools/migration/mucho-migrate.sh \
    --source-host=HOST \
    --source-db=DATABASE \
    --source-user=USER

Apply:
  sudo ./tools/migration/mucho-migrate.sh \
    --source-host=HOST \
    --source-db=DATABASE \
    --source-user=USER \
    --apply --confirm=COVOLTON

Options:
  --source-host=HOST   Source MariaDB/MySQL hostname or IP.
  --source-port=PORT   Source database port (default: 3306).
  --source-db=DB       Source database name.
  --source-user=USER   Source database user.
  --apply              Create a verified target backup, then import.
  --confirm=COVOLTON   Required with --apply.
  --help               Show this help.

Password:
  Set CVOLTON_SOURCE_PASS to avoid putting the source password in shell
  history. When it is not set, the kit prompts securely before connecting.

Safety:
  - Dry-run never writes destination data.
  - Source access is opened read-only by the MuchoCore importer.
  - Apply always creates a target database backup first.
  - The target import runs in one transaction and rolls back on failure.
  - A migration report is written under backups/migrations/.
TXT
}

fail() {
    printf '[MuchoCore Migration Kit] ERROR: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '[MuchoCore Migration Kit] %s\n' "$*"
}

while (($#)); do
    case "$1" in
        --source-host=*) SOURCE_HOST="$(printf '%s' "$1" | cut -d= -f2-)" ;;
        --source-port=*) SOURCE_PORT="$(printf '%s' "$1" | cut -d= -f2-)" ;;
        --source-db=*) SOURCE_DB="$(printf '%s' "$1" | cut -d= -f2-)" ;;
        --source-user=*) SOURCE_USER="$(printf '%s' "$1" | cut -d= -f2-)" ;;
        --apply) MODE="apply" ;;
        --confirm=*) CONFIRM="$(printf '%s' "$1" | cut -d= -f2-)" ;;
        --help|-h) usage; exit 0 ;;
        *) fail "Unknown option: $1. Use --help." ;;
    esac
    shift
done

[[ $EUID -eq 0 ]] || fail "Run as root or with sudo."
command -v docker >/dev/null 2>&1 || fail "Docker is required."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is required."
[[ -f "$ROOT/docker-compose.yml" ]] || fail "Run this command from a MuchoCore installation."
[[ -f "$ROOT/bin/import-cvolton-db.php" ]] || fail "Cvolton importer is missing from this MuchoCore release."

[[ -n "$SOURCE_HOST" ]] || fail "--source-host is required."
[[ -n "$SOURCE_DB" ]] || fail "--source-db is required."
[[ -n "$SOURCE_USER" ]] || fail "--source-user is required."
[[ "$SOURCE_HOST" =~ ^[A-Za-z0-9._:-]+$ ]] || fail "Invalid source host."
[[ "$SOURCE_DB" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || fail "Invalid source database name."
[[ "$SOURCE_PORT" =~ ^[0-9]+$ ]] || fail "Invalid source port."
(( SOURCE_PORT >= 1 && SOURCE_PORT <= 65535 )) || fail "Source port must be 1-65535."
USER_LENGTH="$(printf '%s' "$SOURCE_USER" | wc -c | tr -d '[:space:]')"
(( USER_LENGTH <= 128 )) || fail "Source user is too long."

if [[ "$MODE" == "apply" && "$CONFIRM" != "COVOLTON" ]]; then
    fail "Apply mode requires --confirm=COVOLTON."
fi

mkdir -p "$REPORT_DIR"
chmod 700 "$REPORT_DIR"
exec > >(tee -a "$REPORT_FILE") 2>&1

echo "============================================================"
echo "MuchoCore Migration Kit"
echo "Started: $(date -Is)"
echo "Mode: $MODE"
echo "Source: $SOURCE_USER@$SOURCE_HOST:$SOURCE_PORT/$SOURCE_DB"
echo "Target: $ROOT"
echo "Report: $REPORT_FILE"
echo "============================================================"

if [[ -z "$SOURCE_PASS" ]]; then
    read -r -s -p "Source database password: " SOURCE_PASS < /dev/tty
    printf '\n'
fi

APP_CONTAINER="$(docker compose ps -q app 2>/dev/null || true)"
[[ -n "$APP_CONTAINER" ]] || fail "MuchoCore app container is not running. Start it first with: sudo docker compose up -d"

if ! docker inspect -f '{{.State.Running}}' "$APP_CONTAINER" 2>/dev/null | grep -qx 'true'; then
    fail "MuchoCore app container is not running."
fi

info "Checking target database access..."
docker compose exec -T app php /var/www/mucho-core/bin/mucho-healthcheck.php

info "Checking target migration map tables (read-only)..."
docker compose exec -T app php -r '
require "/var/www/mucho-core/vendor/autoload.php";
$db = (new MuchoCore\Database\Database())->connection();
$required = ["mucho_cvolton_account_map", "mucho_cvolton_level_map"];
foreach ($required as $table) {
    $q = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $q->execute([$table]);
    if ((int)$q->fetchColumn() !== 1) {
        fwrite(STDERR, "MISSING_TARGET_TABLE=$table\n");
        exit(2);
    }
}
echo "TARGET_MAPS_OK\n";
'

info "Running Cvolton source preflight (dry-run)..."
docker compose exec -T \
    -e "CVOLTON_SOURCE_PASS=$SOURCE_PASS" \
    app php /var/www/mucho-core/bin/import-cvolton-db.php \
    --source-host="$SOURCE_HOST" \
    --source-port="$SOURCE_PORT" \
    --source-db="$SOURCE_DB" \
    --source-user="$SOURCE_USER"

if [[ "$MODE" == "dry-run" ]]; then
    echo
    echo "DRY-RUN COMPLETE"
    echo "No destination data was modified."
    echo "Review the counts above, then repeat with --apply --confirm=COVOLTON."
    echo "Report saved to: $REPORT_FILE"
    exit 0
fi

echo
echo "============================================================"
echo "Applying migration with mandatory verified target backup"
echo "============================================================"

info "The importer will create and verify the target backup before opening its transaction."
IMPORT_OUTPUT="$(docker compose exec -T \
    -e "CVOLTON_SOURCE_PASS=$SOURCE_PASS" \
    app php /var/www/mucho-core/bin/import-cvolton-db.php \
    --source-host="$SOURCE_HOST" \
    --source-port="$SOURCE_PORT" \
    --source-db="$SOURCE_DB" \
    --source-user="$SOURCE_USER" \
    --apply \
    --confirm=COVOLTON)"

printf '%s\n' "$IMPORT_OUTPUT"

BACKUP_FILE="$(printf '%s\n' "$IMPORT_OUTPUT" | sed -n 's/^TARGET_BACKUP=//p' | tail -n1)"
[[ -n "$BACKUP_FILE" ]] || fail "Migration did not report a verified target backup."

BACKUP_REL="$(printf '%s' "$BACKUP_FILE" | sed 's#^/var/www/mucho-core/##')"
[[ "$BACKUP_REL" != "$BACKUP_FILE" ]] || fail "Backup path did not originate from the MuchoCore container."
[[ -f "$ROOT/$BACKUP_REL" ]] || fail "Verified target backup was not found on the host: $BACKUP_FILE"

info "Verified target backup: $BACKUP_FILE"

info "Running post-migration healthcheck..."
docker compose exec -T app php /var/www/mucho-core/bin/mucho-healthcheck.php

echo
echo "============================================================"
echo "MIGRATION COMPLETE"
echo "============================================================"
echo "Target backup: $BACKUP_FILE"
echo "Report: $REPORT_FILE"
echo
echo "Next:"
echo "  1. Test representative migrated accounts."
echo "  2. Test representative migrated levels and scores."
echo "  3. Test with the matching Geometry Dash client."
echo "  4. Keep the verified backup until production cutover is complete."
