#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="${MUCHO_REPO_ROOT:-https://raw.githubusercontent.com/IZKGMD/GMDmucho-core}"
RELEASE_API="${MUCHO_RELEASE_API:-https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest}"

[[ $EUID -eq 0 ]] || {
  echo '[MuchoCore] Run the remote installer as root: sudo bash' >&2
  exit 1
}

command -v curl >/dev/null 2>&1 || {
  echo '[MuchoCore] curl is required by the remote installer.' >&2
  exit 1
}

response="$(curl -4fsS --retry 3 --retry-delay 1 \
  --connect-timeout 5 --max-time 15 \
  -H 'Accept: application/vnd.github+json' \
  -H 'User-Agent: MuchoCore-Bootstrap/1.0' \
  -H 'X-GitHub-Api-Version: 2022-11-28' \
  "$RELEASE_API")"

tag="$(printf '%s' "$response" | sed -n 's/.*"tag_name":[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
[[ "$tag" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
  echo '[MuchoCore] Could not resolve the latest published stable release.' >&2
  exit 1
}

tmp="$(mktemp)"
cleanup() {
  rm -f "$tmp"
}
trap cleanup EXIT

echo "[MuchoCore] Preparing stable release $tag..."
curl -4fsSL --retry 3 --retry-delay 1 \
  --connect-timeout 5 --max-time 30 \
  "${REPO_ROOT}/${tag}/install.sh" \
  -o "$tmp"

chmod 700 "$tmp"
exec bash "$tmp" "$@"
