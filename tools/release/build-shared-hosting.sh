#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VERSION="${VERSION:-$(tr -d '[:space:]' < "$ROOT/VERSION")}"
OUTPUT="${OUTPUT:-$ROOT/MuchoCore-v${VERSION}-shared-hosting.zip}"
if [[ "$OUTPUT" != /* ]]; then
  OUTPUT="$ROOT/${OUTPUT#./}"
fi
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
  { echo "Invalid VERSION: $VERSION" >&2; exit 1; }

required=(
  ".htaccess"
  "public"
  "src"
  "database"
  "config"
  "vendor"
  "composer.json"
  "composer.lock"
  "VERSION"
  "README.md"
  "START_HERE.md"
  "docs/SHARED_HOSTING.md"
  "docs/CLIENT_SETUP.md"
)

for path in "${required[@]}"; do
  [[ -e "$ROOT/$path" ]] || {
    echo "Missing shared-hosting package input: $path" >&2
    exit 1
  }
done

mkdir -p "$STAGE/muchocore"

cp -a "$ROOT/.htaccess" "$STAGE/muchocore/"
cp -a "$ROOT/public" "$STAGE/muchocore/"
cp -a "$ROOT/src" "$STAGE/muchocore/"
cp -a "$ROOT/database" "$STAGE/muchocore/"
cp -a "$ROOT/config" "$STAGE/muchocore/"
cp -a "$ROOT/vendor" "$STAGE/muchocore/"
cp -a "$ROOT/composer.json" "$ROOT/composer.lock" "$ROOT/VERSION" "$ROOT/README.md" "$ROOT/START_HERE.md" "$STAGE/muchocore/"
mkdir -p "$STAGE/muchocore/docs"
cp -a "$ROOT/docs/SHARED_HOSTING.md" "$ROOT/docs/CLIENT_SETUP.md" "$STAGE/muchocore/docs/"

rm -f "$STAGE/muchocore/config/cloudsave.key"

# The shared-hosting runtime must not receive the MuchoGDPS control-plane
# installer/deployment UI. Those routes belong only to muchogdps.space.
rm -rf "$STAGE/muchocore/public/install" "$STAGE/muchocore/public/deploy"
rm -f "$STAGE/muchocore/public/deploy.php"

rm -rf "$STAGE/muchocore/storage" "$STAGE/muchocore/.secrets" "$STAGE/muchocore/.git"

mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
(
  cd "$STAGE"
  zip -qr "$OUTPUT" muchocore
)

unzip -t "$OUTPUT" >/dev/null
unzip -Z1 "$OUTPUT" | grep -Fxq 'muchocore/public/shared-install.php'
unzip -Z1 "$OUTPUT" | grep -Fxq 'muchocore/public/ftp-install.php'
unzip -Z1 "$OUTPUT" | grep -Fxq 'muchocore/vendor/autoload.php'
unzip -Z1 "$OUTPUT" | grep -Fxq 'muchocore/docs/SHARED_HOSTING.md'
! unzip -Z1 "$OUTPUT" | grep -Eq '(^|/)\.env($|\.)'
! unzip -Z1 "$OUTPUT" | grep -Eq '(^|/)(\.secrets|storage)/'
! unzip -Z1 "$OUTPUT" | grep -Eq '(^|/)config/cloudsave\.key$'
! unzip -Z1 "$OUTPUT" | grep -Eq '(^|/)\.git/'

echo "SHARED_HOSTING_ARCHIVE_OK"
echo "OUTPUT=$OUTPUT"
