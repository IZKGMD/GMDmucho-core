#!/usr/bin/env bash
# Preserve local VPS commits while incorporating the latest origin/main.
# This deliberately does NOT use update.sh (which resets source to a release/ref).
set -Eeuo pipefail
umask 077

ROOT="${MUCHO_CORE_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$ROOT"

log() { printf '[MuchoCore safe sync] %s\n' "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "Run with sudo/root."
[[ -f "$ROOT/docker-compose.yml" && -f "$ROOT/.env" ]] || die "Set MUCHO_CORE_ROOT to the installed MuchoCore checkout containing .env."
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "Not inside a Git repository."
for tool in git docker flock sha256sum; do
    command -v "$tool" >/dev/null 2>&1 || die "Missing required executable: $tool"
done

# Share the same lock as the standard installer/updater.
exec 9>/run/muchocore-update.lock
flock -n 9 || die "Another MuchoCore update is currently running."

[[ -z "$(git rev-parse -q --verify MERGE_HEAD 2>/dev/null || true)" ]] || die "An unfinished merge exists; resolve it manually first."
git diff --quiet && git diff --cached --quiet || die "Tracked files have local changes; commit or back them up first."

COMPOSE=(docker compose -f docker-compose.yml)
if grep -Eq '^MUCHO_TRANSPORT_MODE="?tunnel"?$' "$ROOT/.env" || \
   [[ -s "$ROOT/.secrets/tunnel_token" ]] || \
   grep -Eq '^MUCHO_TUNNEL_TOKEN=[^[:space:]]+' "$ROOT/.env"; then
    COMPOSE+=(-f docker-compose.tunnel.yml)
    if [[ -s "$ROOT/.secrets/tunnel_token" ]]; then
        export MUCHO_TUNNEL_TOKEN
        MUCHO_TUNNEL_TOKEN="$(cat "$ROOT/.secrets/tunnel_token")"
    fi
fi
"${COMPOSE[@]}" config -q || die "Docker Compose configuration is invalid."

log 'Fetching origin/main (without resetting local commits)...'
git fetch origin 'refs/heads/main:refs/remotes/origin/main'
CURRENT="$(git rev-parse HEAD)"
TARGET="$(git rev-parse origin/main)"
if git merge-base --is-ancestor "$TARGET" "$CURRENT"; then
    log "origin/main ($TARGET) is already merged; checking deployment anyway."
else
    log "Local HEAD: $CURRENT; remote main: $TARGET"
fi

# Docker app image includes the database client and the project backup utility.
# App must be running so its credentials come from the existing runtime secret.
[[ -n "$("${COMPOSE[@]}" ps -q app)" ]] || die 'Application container is not available; no merge performed.'
log 'Creating a required, verified production database backup BEFORE source changes...'
backup_output="$("${COMPOSE[@]}" exec -T -e MUCHO_BACKUP_REQUIRED=1 app bash bin/mucho-db-backup.sh)" || die "Database backup failed; no merge performed."
printf '%s\n' "$backup_output"
grep -qx 'BACKUP_OK' <<< "$backup_output" || die 'Database backup did not report BACKUP_OK.'
backup_container_path="$(sed -n 's/^FILE=//p' <<< "$backup_output" | tail -n1)"
case "$backup_container_path" in
    /var/www/mucho-core/backups/database/*.sql.gz)
        backup_host_path="$ROOT/${backup_container_path#/var/www/mucho-core/}" ;;
    *) die 'Unexpected database backup path; no merge performed.' ;;
esac
[[ -s "$backup_host_path" && -s "$backup_host_path.sha256" ]] || die 'Backup or checksum not visible on host; no merge performed.'
(
    cd "$(dirname "$backup_host_path")"
    sha256sum -c "$(basename "$backup_host_path.sha256")"
) || die 'Database backup checksum invalid; no merge performed.'

backup_branch="backup/vps-safe-sync-$(date -u +%Y%m%dT%H%M%SZ)-$$"
git branch "$backup_branch" "$CURRENT"
log "Source backup branch: $backup_branch"
log "Database backup: $backup_host_path"

if ! git merge --no-ff --no-edit origin/main; then
    die "Git merge stopped. Resolve conflicts; NO reset and NO deployment executed. Database backup: $backup_host_path"
fi

log 'Building and starting the updated Docker services (entrypoint runs migrations)...'
"${COMPOSE[@]}" up -d --build --remove-orphans || die "Container update failed. Source backup: $backup_branch; database backup: $backup_host_path"
log 'Completing migrations and admin synchronization...'
"${COMPOSE[@]}" exec -T app php bin/migrate.php migrate || die "Migration failed. Database backup: $backup_host_path; do not run a destructive rollback."
"${COMPOSE[@]}" exec -T app php bin/mucho-sync-admin.php || die 'Admin synchronization failed.'
"${COMPOSE[@]}" exec -T app php bin/migrate.php status || die 'Migration status check failed.'

log 'Checking GD 2.209 feature configuration (some optional flags/rotations may need manual setup)...'
if ! "${COMPOSE[@]}" exec -T app php tools/diagnostics/gd2209-feature-check.php; then
    log 'WARNING: GD 2.209 feature configuration is incomplete; see checks above. Deployment can still be healthy.'
fi
log 'Running MuchoCore Doctor...'
if [[ -x "$ROOT/bin/mucho" ]]; then
    "$ROOT/bin/mucho" doctor || die 'Doctor reported deployment issues; inspect before claiming production readiness.'
fi
"${COMPOSE[@]}" ps
log "Complete. HEAD=$(git rev-parse --short HEAD); backup=$backup_host_path"
log 'Daily/Weekly/Event still need real eligible level IDs and active schedules; not auto-seeded.'
