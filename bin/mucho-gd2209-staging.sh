#!/usr/bin/env bash
# Isolated 2.209 staging deployment. Never modifies /opt/mucho-core,
# never uses the production Compose project or Docker named volumes.
set -Eeuo pipefail
umask 077

REPO="https://github.com/IZKGMD/GMDmucho-core.git"
BRANCH="fix/gd2209-compat-trace-20261017"
STAGE="/opt/mucho-gd2209-staging"
PROJECT="muchocore-gd2209-staging"
COMPOSE_FILES=(-f docker-compose.test.yml -f docker-compose.gd2209-stage.yml)
MODE="${1:-help}"

die() { echo "[GD209 stage] ERROR: $*" >&2; exit 1; }
note() { echo "[GD209 stage] $*"; }
need() { command -v "$1" >/dev/null 2>&1 || die "Install $1 first."; }

guard() {
    [[ "$STAGE" == "/opt/mucho-gd2209-staging" ]] ||
        die "Stage root is not the isolated checkout."
    [[ "$STAGE" != "/opt/mucho-core" ]] ||
        die "Stage root points to production."
    [[ "$(readlink -f /opt/mucho-core 2>/dev/null || true)" !=
       "$(readlink -f "$STAGE" 2>/dev/null || echo missing)" ]] ||
        die "Stage directory resolves to production."
}

compose_stage() {
    ( cd "$STAGE"
      docker compose --project-name "$PROJECT" "${COMPOSE_FILES[@]}" "$@"
    )
}

init_checkout() {
    if [[ ! -e "$STAGE" ]]; then
        mkdir -p /opt
        note "Cloning isolated staging source (no production checkout edits)..."
        git clone --no-tags --depth=1 --single-branch --branch "$BRANCH" "$REPO" "$STAGE"
    fi
    [[ -d "$STAGE/.git" ]] || die "$STAGE is not a Git clone."
    local origin
    origin="$(git -C "$STAGE" config --get remote.origin.url || true)"
    [[ "$origin" == "$REPO" || "$origin" == "https://github.com/IZKGMD/GMDmucho-core" ]] ||
        die "Refusing to update an unexpected Git repository: $origin"
    [[ -z "$(git -C "$STAGE" status --porcelain --untracked-files=no)" ]] ||
        die "Staging checkout contains modified tracked files; no reset performed."
    git -C "$STAGE" fetch --depth=1 origin "$BRANCH"
    git -C "$STAGE" checkout --detach FETCH_HEAD
    note "Staging commit $(git -C "$STAGE" rev-parse --short HEAD)"
}

init_stage_secrets() {
    mkdir -p "$STAGE/.secrets"
    chmod 700 "$STAGE/.secrets"
    local label n
    for label in testgdps_db_password testgdps_db_root_password testgdps_admin_password; do
        if [[ ! -s "$STAGE/.secrets/$label" ]]; then
            openssl rand -hex 32 > "$STAGE/.secrets/$label"
        fi
        chmod 600 "$STAGE/.secrets/$label"
    done
    # The stage checkout is never allowed to reuse production secrets.
    # App entrypoint receives its MariaDB secret solely through this stack.
    if [[ ! -e "$STAGE/.env" ]]; then
        cat > "$STAGE/.env" <<'STAGE_ENV'
MUCHO_AUTO_UPDATE=0
MUCHO_GD_VERSIONS=22
MUCHO_STAGE_MODE=1
MUCHO_CLIENT_TRACE=1
MUCHO_CLIENT_TRACE_FILE=/var/www/mucho-core/storage/testgdps-client-trace.ndjson
MUCHO_ACCOUNT_URL=http://testgdps.local
MUCHOCORE_SITE_HOST=disabled.invalid
MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net
STAGE_ENV
        chmod 600 "$STAGE/.env"
    fi
    [[ ! -L "$STAGE/.env" && ! -L "$STAGE/.secrets" ]] ||
        die "Symlinked secrets or environment could escape staging!"
    if grep -Eq '^MUCHO_AUTO_UPDATE=1$' "$STAGE/.env"; then
        die "Staging auto-update is enabled; refusing to start."
    fi
}

smoke() {
    need curl
    local body
    body="$(curl --fail --silent --show-error --max-time 15 http://127.0.0.1:18080/health)" ||
        die "Local staging HTTP health check failed."
    [[ "$body" == "1" ]] || die "Unexpected staging health body."
    body="$(curl --fail --silent --show-error --max-time 15 \
        -X POST --data 'accountID=0&gjp2=&binaryVersion=51&gameVersion=22' \
        http://127.0.0.1:18080/database/adminGetReportQueue.php)" ||
        die "Staging queue route did not respond."
    [[ "$body" == "-1" ]] ||
        die "Unauthenticated staging moderator route did not reject access."
    note "PASS staging /health and unauthenticated adminGetReportQueue denial."
    note "Next: configure a SEPARATE HTTPS staging hostname to test the real game."
}

case "$MODE" in
    start)
        guard; need git; need docker; need openssl; need curl
        docker compose version >/dev/null 2>&1 || die "Docker Compose plugin is unavailable."
        init_checkout
        init_stage_secrets
        # No 'down -v', no production paths, no production network/ports.
        compose_stage config --quiet || die "Stage Docker Compose configuration is invalid."
        compose_stage up -d --build testgdps-db testgdps-app gd2209-stage-web
        smoke
        note "Stage: $STAGE | Compose project: $PROJECT"
        note "Listener: http://127.0.0.1:18080 (VPS loopback only)"
        ;;
    smoke)
        guard; [[ -d "$STAGE/.git" ]] || die "Stage not installed"; smoke ;;
    status)
        guard; [[ -d "$STAGE/.git" ]] || die "Stage not installed"
        compose_stage ps ;;
    logs)
        guard; [[ -d "$STAGE/.git" ]] || die "Stage not installed"
        compose_stage logs --tail=70 testgdps-app gd2209-stage-web ;;
    stop)
        guard; [[ -d "$STAGE/.git" ]] || die "Stage not installed"
        compose_stage stop
        note "Only staging containers stopped; staging database volume retained."
        ;;
    *)
        cat <<'USAGE'
Usage: bash bin/mucho-gd2209-staging.sh {start|smoke|status|logs|stop}
Starts independent MariaDB + PHP + Caddy, on VPS loopback 127.0.0.1:18080.
Requires Docker Compose, git, openssl and curl. Do not run from a production
source checkout; the script downloads a fresh branch to /opt/mucho-gd2209-staging.
Does not bind 80/443, patch clients, modify prod or publish a test endpoint.
Do not use 'docker compose down -v' (would erase the test database).
USAGE
        ;;
esac
