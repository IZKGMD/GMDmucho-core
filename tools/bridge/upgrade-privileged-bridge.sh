#!/usr/bin/env bash
# One-time privilege upgrade for an already connected MuchoCore VPS bridge.
# Run locally AS ROOT. Does not need GitHub login or new Secrets.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

[[ "${EUID}" -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
id muchobridge >/dev/null 2>&1 || { echo "Run the original bridge installer first." >&2; exit 2; }
command -v visudo >/dev/null || { echo "sudo/visudo is required." >&2; exit 3; }
command -v curl >/dev/null || { echo "curl required." >&2; exit 3; }

readonly BASE='https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge'
readonly DIR='/root/mucho-bridge-setup'
readonly SUDOERS='/etc/sudoers.d/90-muchocore-bridge'
readonly PRIVILEGED='/usr/local/libexec/mucho-vps-admin'
readonly SSH_COMMAND='/usr/local/libexec/mucho-vps-bridge'
install -d -o root -g root -m 700 "$DIR"

for filename in vps-bridge-admin.sh vps-bridge-remote.sh; do
  curl --fail --silent --show-error --location --retry 2 \
    --connect-timeout 10 --max-time 25 \
    "$BASE/$filename" -o "$DIR/$filename"
  bash -n "$DIR/$filename"
done

# Install in a directory that unprivileged muchobridge cannot modify.
install -d -o root -g root -m 755 /usr/local/libexec
install -o root -g root -m 755 "$DIR/vps-bridge-admin.sh" "$PRIVILEGED"
install -o root -g root -m 755 "$DIR/vps-bridge-remote.sh" "$SSH_COMMAND"

sudoers_temp="$(mktemp)"
trap 'rm -f "$sudoers_temp"' EXIT
cat > "$sudoers_temp" <<'SUDOERS'
# MuchoCore bridge: only named operations of a root-owned helper.
# No wildcard commands, no shell, no Docker socket group membership.
muchobridge ALL=(root) NOPASSWD: /usr/local/libexec/mucho-vps-admin stress-status, /usr/local/libexec/mucho-vps-admin stress-restart, /usr/local/libexec/mucho-vps-admin stress-update
SUDOERS
chmod 440 "$sudoers_temp"
visudo -cf "$sudoers_temp" >/dev/null
install -o root -g root -m 440 "$sudoers_temp" "$SUDOERS"
visudo -cf /etc/sudoers >/dev/null ||
  { rm -f "$SUDOERS"; echo "sudoers validation failed, reverted." >&2; exit 4; }

echo 'Privileged bridge actions installed.'
echo 'Available: /vps stress status, /vps stress restart, /vps stress update.'
echo 'Only /opt/mucho-stress is affected; production MuchoCore is excluded.'
