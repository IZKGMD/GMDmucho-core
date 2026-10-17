#!/usr/bin/env bash
set -Eeuo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/.secrets"
cp "$REPO/update.sh" "$FIXTURE/update.sh"
sed -i "s|exec 9>/run/muchocore-update.lock|exec 9>$FIXTURE/update.lock|" "$FIXTURE/update.sh"
sed -i '/^\[\[ \$EUID -eq 0 \]\] ||/d' "$FIXTURE/update.sh"
if grep -q '^\[\[ \$EUID -eq 0' "$FIXTURE/update.sh"; then
    echo 'Failed to isolate the updater root guard' >&2; exit 1
fi
# No host lock, Docker service, Git checkout or /usr/local symlink is touched.
printf 'isolated-key\n' > "$FIXTURE/.secrets/cloudsave_key"
printf '1.0.0\n' > "$FIXTURE/VERSION"
cat > "$FIXTURE/.env" <<'ENV'
DOMAIN=gdps.example.test
ADMIN_USER=admin
MUCHO_TUNNEL_TOKEN=isolated-tunnel-token
ENV
export UPDATE_TEST_ROOT="$FIXTURE"
export UPDATE_TEST_OLD_SHA=1111111111111111111111111111111111111111
export UPDATE_TEST_NEW_SHA=2222222222222222222222222222222222222222
export UPDATE_TEST_TAG_SHA=3333333333333333333333333333333333333333
printf '%s\n' "$UPDATE_TEST_OLD_SHA" > "$FIXTURE/head"

cat > "$FIXTURE/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf 'git %s\n' "$*" >> "$UPDATE_TEST_ROOT/actions"
case "$*" in
    'diff --quiet'|'diff --cached --quiet'|'merge-base --is-ancestor '*) exit 0 ;;
    'rev-parse HEAD') cat "$UPDATE_TEST_ROOT/head" ;;
    'rev-parse origin/main') printf '%s\n' "$UPDATE_TEST_NEW_SHA" ;;
    'rev-parse --is-shallow-repository') echo false ;;
    'fetch origin refs/heads/main:refs/remotes/origin/main') exit 0 ;;
    'fetch origin refs/tags/v1.0.1:refs/tags/v1.0.1') touch "$UPDATE_TEST_ROOT/tag-fetched" ;;
    'ls-remote origin refs/tags/v1.0.1 refs/tags/v1.0.1^{}')
        printf '%s\trefs/tags/v1.0.1\n%s\trefs/tags/v1.0.1^{}\n' "$UPDATE_TEST_TAG_SHA" "$UPDATE_TEST_NEW_SHA" ;;
    'reset --hard origin/main') printf '%s\n' "$UPDATE_TEST_NEW_SHA" > "$UPDATE_TEST_ROOT/head" ;;
    'reset --hard v1.0.1')
        test -f "$UPDATE_TEST_ROOT/tag-fetched"
        printf '%s\n' "$UPDATE_TEST_NEW_SHA" > "$UPDATE_TEST_ROOT/head" ;;
    "reset --hard $UPDATE_TEST_OLD_SHA"|"reset --hard $UPDATE_TEST_NEW_SHA") printf '%s\n' "$3" > "$UPDATE_TEST_ROOT/head" ;;
    *) echo "UNEXPECTED_GIT: $*" >&2; exit 42 ;;
esac
MOCK
cat > "$FIXTURE/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf 'docker %s\n' "$*" >> "$UPDATE_TEST_ROOT/actions"
args="$*"
[[ "$args" == 'compose -f docker-compose.yml -f docker-compose.tunnel.yml '* ]] || {
    echo 'Missing tunnel Compose files' >&2; exit 44;
}
case "$args" in
    *' up -d --build --remove-orphans')
        if [[ "${UPDATE_TEST_FAIL:-}" == build ]]; then exit 45; fi
        if [[ "${UPDATE_TEST_FAIL:-}" == signal ]]; then kill -TERM "$PPID"; fi ;;
    *' exec -T app php --version') [[ "${UPDATE_TEST_FAIL:-}" != runtime ]] ;;
    *' exec -T app composer install '*) [[ "${UPDATE_TEST_FAIL:-}" != composer ]] ;;
    *' exec -T app php bin/migrate.php migrate') [[ "${UPDATE_TEST_FAIL:-}" != migration ]] ;;
    *' exec -T app php bin/mucho-sync-admin.php') [[ "${UPDATE_TEST_FAIL:-}" != admin ]] ;;
    *' exec -T caddy caddy reload '*) [[ "${UPDATE_TEST_FAIL:-}" != routes ]] ;;
    *' exec -T app php bin/mucho-client-patch.php') [[ "${UPDATE_TEST_FAIL:-}" != patch ]] ;;
    *' ps') exit 0 ;;
    *) echo "UNEXPECTED_DOCKER: $*" >&2; exit 43 ;;
esac
MOCK
cat > "$FIXTURE/bin/curl" <<'MOCK'
#!/usr/bin/env bash
echo '{"tag_name":"v1.0.1"}'
MOCK
chmod +x "$FIXTURE/bin/"*

run_update() {
    set +e
    output="$(cd "$FIXTURE" && PATH="$FIXTURE/bin:$PATH" \
        MUCHO_UPDATE_CHANNEL="${UPDATE_TEST_CHANNEL:-main}" MUCHO_UPDATE_REPAIR="${UPDATE_TEST_REPAIR:-0}" \
        UPDATE_TEST_FAIL="${UPDATE_TEST_FAIL:-}" bash ./update.sh 2>&1)"
    code=$?
    set -e
}
check() {
    if ! "$@"; then printf 'FAIL: %s\n%s\n' "$*" "$output" >&2; exit 1; fi
}

for phase in build runtime composer signal; do
    printf '%s\n' "$UPDATE_TEST_OLD_SHA" > "$FIXTURE/head"
    UPDATE_TEST_FAIL="$phase" run_update
    check test "$code" -ne 0
    check test "$(cat "$FIXTURE/head")" = "$UPDATE_TEST_OLD_SHA"
    check test -f "$FIXTURE/.muchocore/update.pending"
    echo "PASS failed $phase restores original source and retains deployment checkpoint"
done

for phase in migration admin routes; do
    printf '%s\n' "$UPDATE_TEST_OLD_SHA" > "$FIXTURE/head"
    UPDATE_TEST_FAIL="$phase" run_update
    check test "$code" -ne 0
    check test "$(cat "$FIXTURE/head")" = "$UPDATE_TEST_NEW_SHA"
    check test -f "$FIXTURE/.muchocore/update.pending"
    : > "$FIXTURE/actions"
    run_update
    check test "$code" -eq 0
    check grep -Fq 'Completing or repairing deployment' <<< "$output"
    check grep -Fq 'exec -T app php bin/migrate.php migrate' "$FIXTURE/actions"
    check grep -Fq 'exec -T caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile' "$FIXTURE/actions"
    check test ! -f "$FIXTURE/.muchocore/update.pending"
    echo "PASS $phase failure is resumed at the same SHA, with migrations and tunnel configuration"
done

: > "$FIXTURE/actions"
run_update
check test "$code" -eq 0
check grep -Fq 'Already on the latest development source' <<< "$output"
check test "$(grep -c '^docker ' "$FIXTURE/actions" || true)" -eq 0
echo 'PASS completed deployment remains a fast no-op'

UPDATE_TEST_REPAIR=1 run_update
check test "$code" -eq 0
check grep -Fq 'Completing or repairing deployment' <<< "$output"
echo 'PASS explicit repair re-applies an already installed source'

UPDATE_TEST_REPAIR=bad run_update
check test "$code" -ne 0
echo 'PASS invalid repair option is rejected'

printf '%s\n' "$UPDATE_TEST_OLD_SHA" > "$FIXTURE/head"
UPDATE_TEST_CHANNEL=stable run_update
check test "$code" -eq 0
check test "$(cat "$FIXTURE/head")" = "$UPDATE_TEST_NEW_SHA"
check test -f "$FIXTURE/tag-fetched"
printf '1.0.1\n' > "$FIXTURE/VERSION"
UPDATE_TEST_CHANNEL=stable run_update
check test "$code" -eq 0
check grep -Fq 'Already on the latest stable release' <<< "$output"
echo 'PASS stable releases fetch their exact tag and recognize annotated tag commits'

UPDATE_TEST_REPAIR=1 UPDATE_TEST_FAIL=patch run_update
check test "$code" -eq 0
check test ! -f "$FIXTURE/.muchocore/update.pending"
check grep -Fq 'automatic client repatching failed' <<< "$output"
echo 'PASS optional repatching failure does not block a completed core deployment'
echo 'MUCHOCORE_UPDATE_RECOVERY_OK'
