#!/usr/bin/env bash
set -Eeuo pipefail

REPO_ROOT="${MUCHO_REPO_ROOT:-https://raw.githubusercontent.com/IZKGMD/GMDmucho-core}"
RELEASE_API="${MUCHO_RELEASE_API:-https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest}"
INSTALL_REF="${MUCHO_INSTALL_REF:-}"

# Keep bootstrap selection explicit and deterministic. Passing --ref here must
# select the same installer ref that the inner install.sh will use.
for arg in "$@"; do
  case "$arg" in
    --ref=*) INSTALL_REF="${arg#*=}" ;;
  esac
done

[[ $EUID -eq 0 ]] || {
  echo '[MuchoCore] Run the remote installer as root: sudo bash' >&2
  exit 1
}

command -v curl >/dev/null 2>&1 || {
  echo '[MuchoCore] curl is required by the remote installer.' >&2
  exit 1
}

if [[ -n "$INSTALL_REF" ]]; then
  [[ "$INSTALL_REF" =~ ^[A-Za-z0-9._/-]+$ ]] || {
    echo "[MuchoCore] Invalid installer ref: $INSTALL_REF" >&2
    exit 1
  }
  SOURCE_REF="$INSTALL_REF"
  echo "[MuchoCore] Using installer ref: $SOURCE_REF"
else
  response="$(curl -4fsS --retry 3 --retry-delay 1 \
    --connect-timeout 5 --max-time 15 \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: MuchoCore-Bootstrap/1.0' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "$RELEASE_API")"
  SOURCE_REF="$(printf '%s' "$response" | sed -n 's/.*"tag_name":[[:space:]]*"\([^"]*\)".*/\1/p' | head -n1)"
  [[ "$SOURCE_REF" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || {
    echo '[MuchoCore] Could not resolve the latest published stable release.' >&2
    exit 1
  }
  echo "[MuchoCore] Using published release: $SOURCE_REF"
fi

tmp="$(mktemp)"
cleanup() {
  rm -f "$tmp"
}
trap cleanup EXIT

echo "[MuchoCore] Preparing installer ref $SOURCE_REF..."

# Fetch the installer directly from the selected Git ref. Do not resolve or
# substitute Git object/blob SHAs here: that added failure modes (commit SHA
# vs content SHA) without helping the one-command bootstrap.
cache_bust="$(date +%s%N 2>/dev/null || date +%s)"
installer_url="${REPO_ROOT}/${SOURCE_REF}/install.sh?cb=${cache_bust}"
curl -4fsSL --retry 3 --retry-delay 1 \
  --connect-timeout 5 --max-time 30 \
  "$installer_url" \
  -o "$tmp"

# A tiny integrity guard catches an HTML/error response masquerading as a
# downloaded installer before bash executes it.
grep -q '^#!/usr/bin/env bash$' "$tmp" || {
  echo "[MuchoCore] Downloaded installer is not a valid Bash script: $installer_url" >&2
  exit 1
}
chmod 700 "$tmp"

# Keep an explicitly selected bootstrap ref visible to the inner installer.
if [[ -n "$INSTALL_REF" ]]; then
  export MUCHO_INSTALL_REF="$INSTALL_REF"
fi

exec bash "$tmp" "$@"
