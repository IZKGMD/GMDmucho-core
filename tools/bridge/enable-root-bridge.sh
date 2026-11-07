#!/usr/bin/env bash
# Explicit one-time opt-in for UNRESTRICTED root execution via GitHub issue #205.
# Intended ONLY for disposable MuchoCore test VPS infrastructure.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

[[ "${EUID}" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
id muchobridge >/dev/null 2>&1 ||
  { echo "Install the normal GitHub VPS bridge first." >&2; exit 2; }
command -v visudo >/dev/null || { echo "sudo and visudo are required." >&2; exit 3; }
command -v curl >/dev/null || { echo "curl required." >&2; exit 3; }
command -v python3 >/dev/null || { echo "python3 required." >&2; exit 3; }
command -v timeout >/dev/null || { echo "coreutils timeout required." >&2; exit 3; }

readonly BASE='https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge'
readonly WORK='/root/mucho-bridge-setup'
readonly HELPER='/usr/local/libexec/mucho-vps-root-exec'
readonly FORCED='/usr/local/libexec/mucho-vps-bridge'
readonly POLICY='/etc/sudoers.d/91-muchocore-bridge-root'

install -d -o root -g root -m 700 "$WORK"
for name in vps-bridge-root-exec.py vps-bridge-remote.sh; do
  curl --fail --silent --show-error --location --retry 2 \
    --connect-timeout 10 --max-time 30 "$BASE/$name" -o "$WORK/$name"
done
python3 -m py_compile "$WORK/vps-bridge-root-exec.py"
bash -n "$WORK/vps-bridge-remote.sh"

# The root executor and forced SSH command cannot be modified by muchobridge.
install -d -o root -g root -m 755 /usr/local/libexec
install -o root -g root -m 755 "$WORK/vps-bridge-root-exec.py" "$HELPER"
install -o root -g root -m 755 "$WORK/vps-bridge-remote.sh" "$FORCED"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
cat > "$tmp" <<'POLICY'
# Owner-approved FULL root execution on a DISPOSABLE TEST VPS only.
# This helper itself validates and decodes one base64url transport argument.
muchobridge ALL=(root) NOPASSWD: /usr/local/libexec/mucho-vps-root-exec *
POLICY
chmod 440 "$tmp"
visudo -cf "$tmp" >/dev/null
install -o root -g root -m 440 "$tmp" "$POLICY"
if ! visudo -cf /etc/sudoers >/dev/null; then
  rm -f "$POLICY"
  echo "Sudo policy validation failed. Removed root permission." >&2
  exit 4
fi

echo
echo "MuchoCore root bridge: ENABLED (GitHub owner commands only)."
echo "Command channel: https://github.com/IZKGMD/GMDmucho-core/issues/205"
echo "Example comment: /vps root id"
echo "WARNING: Commands AND OUTPUT are visible to repository readers."
echo "DISABLE immediately with: rm -f /etc/sudoers.d/91-muchocore-bridge-root"
