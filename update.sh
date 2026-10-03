#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Run: sudo ./update.sh' >&2; exit 1; }

# Prevent overlapping manual and automatic updates.
exec 9>/run/muchocore-update.lock
if ! flock -n 9; then
    echo '[MuchoCore] Another update is already running; skipping this update.'
    exit 0
fi

# Older MuchoCore versions accidentally overwrote the project .env from inside
# the app container. Recover the domain from the existing Caddy container so
# the first update can repair that installation automatically.
install -d -m 700 "$ROOT/.secrets"

if [[ ! -s "$ROOT/.secrets/cloudsave_key" && ! -s "$ROOT/config/cloudsave.key" ]]; then
    if docker compose ps app >/dev/null 2>&1; then
        docker compose exec -T app cat /var/lib/muchocore/cloudsave.key             > "$ROOT/.secrets/cloudsave_key.tmp" 2>/dev/null || true
        if [[ -s "$ROOT/.secrets/cloudsave_key.tmp" ]]; then
            chmod 600 "$ROOT/.secrets/cloudsave_key.tmp"
            mv "$ROOT/.secrets/cloudsave_key.tmp" "$ROOT/.secrets/cloudsave_key"
        else
            rm -f "$ROOT/.secrets/cloudsave_key.tmp"
        fi
    fi
fi

if ! grep -q '^DOMAIN=' "$ROOT/.env" 2>/dev/null; then
    CADDY_ID="$(docker ps -a --filter 'label=com.docker.compose.service=caddy' --format '{{.ID}}' | head -n1 || true)"
    if [[ -n "$CADDY_ID" ]]; then
        SAVED_DOMAIN="$(docker inspect "$CADDY_ID" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null | sed -n 's/^DOMAIN=//p' | head -n1 || true)"
        if [[ -n "$SAVED_DOMAIN" ]]; then
            printf '\nDOMAIN=%s\n' "$SAVED_DOMAIN" >> "$ROOT/.env"
            echo "[MuchoCore] Recovered DOMAIN=$SAVED_DOMAIN from the existing Caddy container."
        fi
    fi
fi

if ! grep -q '^DOMAIN=' "$ROOT/.env" 2>/dev/null; then
    echo '[MuchoCore] ERROR: DOMAIN is missing from .env and could not be recovered.' >&2
    echo '[MuchoCore] Add DOMAIN=your-domain.example and run update again.' >&2
    exit 1
fi

# Load DOMAIN from the installation config before strict-mode functions use it.
DOMAIN="$(sed -n 's/^DOMAIN=//p' "$ROOT/.env" | head -n1 || true)"
[[ -n "$DOMAIN" ]] || {
    echo '[MuchoCore] ERROR: DOMAIN is empty in .env.' >&2
    exit 1
}

# Optional additional hostnames (for example hosted tenant subdomains).
CADDY_EXTRA_HOSTS="$(sed -n 's/^CADDY_EXTRA_HOSTS=//p' "$ROOT/.env" | head -n1 || true)"
if [[ -n "$CADDY_EXTRA_HOSTS" ]]; then
    for host in $CADDY_EXTRA_HOSTS; do
        [[ "$host" =~ ^[A-Za-z0-9.-]+$ ]] || {
            echo "[MuchoCore] ERROR: invalid CADDY_EXTRA_HOSTS entry: $host" >&2
            exit 1
        }
    done
fi

grep -q '^ADMIN_USER=' "$ROOT/.env" 2>/dev/null || printf '\nADMIN_USER=admin\n' >> "$ROOT/.env"
grep -q '^TZ=' "$ROOT/.env" 2>/dev/null || printf 'TZ=UTC\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SITEKEY=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SITEKEY=\n' >> "$ROOT/.env"
grep -q '^TURNSTILE_SECRET=' "$ROOT/.env" 2>/dev/null || printf 'TURNSTILE_SECRET=\n' >> "$ROOT/.env"
grep -q '^MUCHO_GD_VERSIONS=' "$ROOT/.env" 2>/dev/null || printf 'MUCHO_GD_VERSIONS=all\n' >> "$ROOT/.env"
grep -q '^CADDY_EXTRA_HOSTS=' "$ROOT/.env" 2>/dev/null || printf 'CADDY_EXTRA_HOSTS=\n' >> "$ROOT/.env"
grep -q '^MUCHOCORE_SITE_HOST=' "$ROOT/.env" 2>/dev/null || printf 'MUCHOCORE_SITE_HOST=disabled.invalid\n' >> "$ROOT/.env"
grep -q '^MUCHO_PROTECT_STORAGE=' "$ROOT/.env" 2>/dev/null || printf 'MUCHO_PROTECT_STORAGE=file\n' >> "$ROOT/.env"
grep -q '^MUCHO_TRUSTED_PROXY_CIDRS=' "$ROOT/.env" 2>/dev/null || printf 'MUCHO_TRUSTED_PROXY_CIDRS=\n' >> "$ROOT/.env"
MUCHOCORE_SITE_HOST="$(sed -n 's/^MUCHOCORE_SITE_HOST=//p' "$ROOT/.env" | head -n1 || true)"
[[ "$MUCHOCORE_SITE_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || {
    echo "[MuchoCore] ERROR: invalid MUCHOCORE_SITE_HOST: $MUCHOCORE_SITE_HOST" >&2
    exit 1
}
CADDY_EXTRA_HOSTS="$(sed -n 's/^CADDY_EXTRA_HOSTS=//p' "$ROOT/.env" | head -n1 || true)"

normalize_caddy_address() {
  if [[ -n "$TUNNEL_TOKEN" ]]; then
    printf ':80'
    return
  fi

  local host="$DOMAIN"
  host="${host#http://}"
  host="${host#https://}"
  host="${host%%/*}"

  local root="$host"
  if [[ "$host" == www.* ]]; then
    root="${host#www.}"
  fi

  printf 'http://%s http://www.%s https://%s https://www.%s' "$root" "$root" "$root" "$root"
  local extra="${CADDY_EXTRA_HOSTS:-}"
  if [[ -n "$extra" ]]; then
    printf ' %s' "$extra"
  fi
}
# Serve both the canonical host and the legacy www host used by older GD 1.9 clients.
# Preserve :80 for Cloudflare Tunnel mode; otherwise explicitly serve HTTP + HTTPS.
if grep -q '^MUCHO_TUNNEL_TOKEN=' "$ROOT/.env" 2>/dev/null; then
    TUNNEL_TOKEN="$(sed -n 's/^MUCHO_TUNNEL_TOKEN=//p' "$ROOT/.env" | head -n1 || true)"
else
    TUNNEL_TOKEN=""
fi
CADDY_ADDRESS_VALUE="$(normalize_caddy_address)"
CADDY_ADDRESS="\"$CADDY_ADDRESS_VALUE\""
sed -i '/^CADDY_ADDRESS=/d' "$ROOT/.env"
printf 'CADDY_ADDRESS=%s\n' "$CADDY_ADDRESS" >> "$ROOT/.env"

COMPOSE_ARGS=()
if grep -q '^MUCHO_TUNNEL_TOKEN=' "$ROOT/.env" 2>/dev/null; then
    COMPOSE_ARGS=(-f docker-compose.yml -f docker-compose.tunnel.yml)
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo '[MuchoCore] ERROR: this installation has local changes in tracked files.' >&2
    echo '[MuchoCore] I stopped before reset so your work is not lost.' >&2
    echo '[MuchoCore] Commit or back up your changes, then run update again.' >&2
    exit 1
fi

get_latest_stable_release_tag() {
    local response
    local tag

    response="$(curl -4fsS --connect-timeout 5 --max-time 10 \
        -H 'Accept: application/vnd.github+json' \
        -H 'User-Agent: MuchoCore-Updater/1.0' \
        -H 'X-GitHub-Api-Version: 2022-11-28' \
        'https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest')" || return 1

    tag="$(printf '%s' "$response" |
        sed -n 's/.*"tag_name":[[:space:]]*"\${[^"]*\).*/\1/p' |
        head -n1)"

    [[ "$tag" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || return 1
    printf '%s' "$tag"
}

get_latest_stable_release_tag_fallback() {
    git ls-remote --tags --refs origin 'refs/tags/v[0-9]*' |
        awk '{print $2}' |
        sed 's#refs/tags/v##' |
        grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' |
        sort -V |
        tail -n1 |
        sed 's/^/v/'
}

version_gt() {
    local left="${1#v}"
    local right="${2#v}"

    [[ "$left" != "$right" ]] &&
        [[ "$(printf '%s\n%s\n' "$left" "$right" | sort -V | tail -n1)" == "$left" ]]
}

CURRENT_VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION" 2>/dev/null || true)"
CURRENT_SEMVER="$(printf '%s' "$CURRENT_VERSION" | sed 's/^v//')"
CURRENT_HEAD="$(git rev-parse HEAD)"

LATEST_TAG="$(get_latest_stable_release_tag || true)"
if [[ -z "$LATEST_TAG" ]]; then
    echo '[MuchoCore] GitHub Releases API was unavailable; resolving the latest stable tag from Git instead...'
    LATEST_TAG="$(get_latest_stable_release_tag_fallback || true)"
fi

[[ "$LATEST_TAG" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
    echo '[MuchoCore] ERROR: unable to resolve a published stable GitHub Release.' >&2
    exit 1
}

LATEST_SEMVER="$(printf '%s' "$LATEST_TAG" | sed 's/^v//')"
REMOTE_TAG_SHA="$(git ls-remote origin "refs/tags/$LATEST_TAG" | awk 'NR == 1 {print $1}')"

[[ -n "$REMOTE_TAG_SHA" ]] || {
    echo '[MuchoCore] ERROR: stable release tag could not be resolved.' >&2
    exit 1
}

# Update channel:
#   auto   - stable installations follow the latest release; development
#            installations (VERSION newer than the latest release) follow main.
#   stable - always update to the latest published release.
#   main   - always update to origin/main.
UPDATE_CHANNEL="${MUCHO_UPDATE_CHANNEL:-auto}"
case "$UPDATE_CHANNEL" in
    auto|stable|main) ;;
    *)
        echo "[MuchoCore] ERROR: MUCHO_UPDATE_CHANNEL must be auto, stable, or main." >&2
        exit 1
        ;;
esac

TARGET_REF="$LATEST_TAG"
TARGET_LABEL="v$LATEST_SEMVER"
TARGET_SHA="$REMOTE_TAG_SHA"
TARGET_KIND="stable"

if [[ "$UPDATE_CHANNEL" == "main" ]] ||
   [[ "$UPDATE_CHANNEL" == "auto" && "$(version_gt "$CURRENT_SEMVER" "$LATEST_SEMVER" && printf '1' || true)" == "1" ]]; then

    git fetch origin "refs/heads/main:refs/remotes/origin/main"

    TARGET_REF="origin/main"
    TARGET_LABEL="main"
    TARGET_KIND="development"
    TARGET_SHA="$(git rev-parse origin/main)"

    if [[ "$CURRENT_HEAD" == "$TARGET_SHA" ]]; then
        echo '[MuchoCore] Already on the latest development source: origin/main.'
        exit 0
    fi
else
    if [[ "$CURRENT_SEMVER" == "$LATEST_SEMVER" && "$CURRENT_HEAD" == "$REMOTE_TAG_SHA" ]]; then
        echo "[MuchoCore] Already on the latest stable release: v$CURRENT_SEMVER."
        exit 0
    fi
fi

if [[ "$TARGET_KIND" == "development" ]]; then
    echo "[MuchoCore] Updating development core: v$CURRENT_SEMVER -> $TARGET_LABEL"
else
    echo "[MuchoCore] Updating stable core: v$CURRENT_SEMVER -> $TARGET_LABEL"
fi

# Preserve the previous source tree until the new containers build successfully.
ORIGINAL_HEAD="$(git rev-parse HEAD)"
UPDATE_SOURCE_SWITCHED=0

rollback_source_tree() {
    if [[ "$UPDATE_SOURCE_SWITCHED" != "1" ]]; then
        return 0
    fi

    echo '[MuchoCore] New source build did not complete; restoring the previous source tree...'
    if git reset --hard "$ORIGINAL_HEAD"; then
        UPDATE_SOURCE_SWITCHED=0
        echo '[MuchoCore] Previous source tree restored.'
    else
        echo '[MuchoCore] ERROR: failed to restore the previous source tree.' >&2
        return 1
    fi
}

handle_update_interrupt() {
    rollback_source_tree || true
    exit 130
}

trap handle_update_interrupt INT TERM

# Older releases could leave the installation as a shallow clone. Expand it
# before the ancestry check so valid source upgrades are not rejected.
if [[ "$(git rev-parse --is-shallow-repository)" == "true" ]]; then
    echo "[MuchoCore] Expanding shallow Git history before source validation..."
    git fetch --unshallow origin
fi

if ! git merge-base --is-ancestor "$CURRENT_HEAD" "$TARGET_REF^{commit}"; then
    if [[ "$UPDATE_CHANNEL" == "main" ]]; then
        echo '[MuchoCore] ERROR: development source line is not a descendant of the installed source tree.' >&2
        echo '[MuchoCore] Refusing the development rebase. Set MUCHO_ALLOW_RELEASE_REBASE=1 only for an intentional source-line transition.' >&2
        exit 1
    fi

    if [[ "${MUCHO_ALLOW_RELEASE_REBASE:-0}" != "1" ]]; then
        echo "[MuchoCore] ERROR: $TARGET_LABEL is not a descendant of the installed source tree." >&2
        echo '[MuchoCore] Refusing the source-line rebase. Set MUCHO_ALLOW_RELEASE_REBASE=1 only for an intentional source-line transition.' >&2
        exit 1
    fi
}

git reset --hard "$TARGET_REF"

echo '[MuchoCore] Rebuilding containers...'
if ! docker compose "${COMPOSE_ARGS[@]}" up -d --build --remove-orphans; then
    rollback_source_tree
    exit 1
fi

echo '[MuchoCore] Verifying the production application container...'
if ! docker compose "${COMPOSE_ARGS[@]}" exec -T app php --version >/dev/null 2>&1; then
    rollback_source_tree
    exit 1
fi

echo '[MuchoCore] Updating PHP dependencies...'
if ! docker compose "${COMPOSE_ARGS[@]}" exec -T app composer install --no-dev --optimize-autoloader --no-interaction; then
    rollback_source_tree
    exit 1
fi

# The new source tree and application images passed the pre-migration checks.
UPDATE_SOURCE_SWITCHED=0
trap - INT TERM

echo '[MuchoCore] Applying database migrations...'
docker compose exec -T app php bin/migrate.php migrate

echo '[MuchoCore] Synchronizing admin credentials...'
docker compose exec -T app php bin/mucho-sync-admin.php

echo '[MuchoCore] Auto-patching tenant clients...'
if ! docker compose exec -T app php bin/mucho-client-patch.php; then
    echo '[MuchoCore] WARNING: automatic client repatching failed; core update will continue.' >&2
fi

if [[ -x "$ROOT/bin/mucho" ]]; then ln -sfn "$ROOT/bin/mucho" /usr/local/bin/mucho; fi
if [[ -x "$ROOT/bin/muchodb-password" ]]; then ln -sfn "$ROOT/bin/muchodb-password" /usr/local/bin/muchodb-password; fi

echo '[MuchoCore] Checking service status...'
docker compose "${COMPOSE_ARGS[@]}" ps

echo
echo '[MuchoCore] Compatibility profile:'
sed -n 's/^MUCHO_GD_VERSIONS=/  GD versions: /p' "$ROOT/.env" | sed 's/,/, /g'
