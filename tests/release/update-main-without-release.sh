#!/usr/bin/env bash
set -Eeuo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/.secrets"
cp "$REPO/update.sh" "$FIXTURE/update.sh"

# The release test runs in GitHub Actions without root privileges. Only the
# fixture copy's root check is removed; neither the real script nor the host
# installation is changed.
sed -i "/^\[\[ \\\$EUID -eq 0 \]\] || { echo 'Run: sudo .\/update.sh'/d" "$FIXTURE/update.sh"
if grep -q "^\\[\\[ \\\$EUID -eq 0 \\]\\]" "$FIXTURE/update.sh"; then
    echo 'ERROR: failed to isolate updater root guard' >&2
    exit 1
fi

cat > "$FIXTURE/.env" <<'ENV'
DOMAIN=gdps.example.test
ADMIN_USER=admin
MUCHO_AUTO_UPDATE=0
ENV
printf '1.0.0\n' > "$FIXTURE/VERSION"

cat > "$FIXTURE/bin/git" <<'MOCK'
#!/usr/bin/env bash
case "$*" in
    'diff --quiet'|'diff --cached --quiet'|'fetch origin refs/heads/main:refs/remotes/origin/main')
        exit 0 ;;
    'rev-parse HEAD'|'rev-parse origin/main')
        printf '%040d\n' 1
        exit 0 ;;
    'describe --exact-match --tags '*)
        exit 1 ;;
    *)
        echo "UNEXPECTED_GIT: $*" >&2
        exit 42 ;;
esac
MOCK

cat > "$FIXTURE/bin/docker" <<'MOCK'
#!/usr/bin/env bash
exit 1
MOCK

cat > "$FIXTURE/bin/curl" <<'MOCK'
#!/usr/bin/env bash
echo 'UNEXPECTED_STABLE_RELEASE_LOOKUP' >&2
exit 43
MOCK
chmod +x "$FIXTURE/bin/"*

set +e
main_output="$(cd "$FIXTURE" && PATH="$FIXTURE/bin:$PATH" MUCHO_UPDATE_CHANNEL=main bash ./update.sh 2>&1)"
main_code=$?
set -e
if [[ "$main_code" -ne 0 || "$main_output" != *'Already on the latest development source'* ]]; then
    printf 'ERROR: main update improperly depended on a stable GitHub release:\n%s\n' "$main_output" >&2
    exit 1
fi
if [[ "$main_output" == *UNEXPECTED_STABLE_RELEASE_LOOKUP* ]]; then
    echo 'ERROR: main update attempted to query stable GitHub Releases' >&2
    exit 1
fi

set +e
stable_output="$(cd "$FIXTURE" && PATH="$FIXTURE/bin:$PATH" MUCHO_UPDATE_CHANNEL=stable bash ./update.sh 2>&1)"
stable_code=$?
set -e
if [[ "$stable_code" -eq 0 || "$stable_output" != *'unable to resolve a published stable GitHub Release'* ]]; then
    printf 'ERROR: stable update was not rejected without release evidence:\n%s\n' "$stable_output" >&2
    exit 1
fi

echo 'MUCHOCORE_UPDATE_MAIN_WITHOUT_RELEASE_OK'
