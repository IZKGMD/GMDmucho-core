#!/usr/bin/env bash
# Run once as root on the VPS with the PUBLIC half of a dedicated SSH key:
#   sudo bash tools/bridge/install-vps-bridge.sh /root/mucho-bridge.pub
#
# No modification to /opt/mucho-core, Docker, SSH ports or firewall.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

if (( EUID != 0 )); then
  echo "Run this installer with sudo/root." >&2
  exit 1
fi

pubkey_file="${1:-}"
if [[ -z "$pubkey_file" || ! -f "$pubkey_file" ]]; then
  echo "Usage: sudo bash install-vps-bridge.sh /absolute/path/to/key.pub" >&2
  exit 2
fi
if ! command -v ssh-keygen >/dev/null 2>&1; then
  echo "OpenSSH tools are required." >&2
  exit 3
fi
ssh-keygen -lf "$pubkey_file" >/dev/null

key_line="$(head -n 1 -- "$pubkey_file")"
read -r key_type key_data _ <<< "$key_line"
if [[ "$key_type" != "ssh-ed25519" ||
      ! "$key_data" =~ ^[A-Za-z0-9+/=]+$ ]]; then
  echo "A valid ssh-ed25519 public key is required." >&2
  exit 4
fi

src_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
for file in vps-bridge-remote.sh vps-bridge-bench.py; do
  [[ -f "$src_dir/$file" ]] || {
    echo "Missing $src_dir/$file" >&2
    exit 5
  }
done
bash -n "$src_dir/vps-bridge-remote.sh"
python3 -m py_compile "$src_dir/vps-bridge-bench.py"

bridge_user='muchobridge'
bridge_home='/var/lib/mucho-bridge'
if id -u "$bridge_user" >/dev/null 2>&1; then
  actual_home="$(getent passwd "$bridge_user" | cut -d: -f6)"
  if [[ "$actual_home" != "$bridge_home" ]]; then
    echo "Existing muchobridge user has an unexpected home; refusing." >&2
    exit 6
  fi
else
  useradd --create-home --home-dir "$bridge_home" --shell /bin/bash \
    --user-group "$bridge_user"
fi

# Avoid docker/sudo: either group would give the bridge broad host control.
for group in $(id -nG "$bridge_user"); do
  case "$group" in
    sudo|docker|wheel|adm) echo "Bridge user has privileged group $group; refusing." >&2; exit 7 ;;
  esac
done

for path in "$bridge_home" "$bridge_home/.ssh" "$bridge_home/.ssh/authorized_keys"; do
  if [[ -L "$path" ]]; then
    echo "Refusing symlink at $path" >&2
    exit 8
  fi
done

# The home and .ssh directory are root-owned: the bridge user cannot add
# unrestricted authorized_keys or replace the forced command.
install -d -o root -g root -m 755 "$bridge_home"
install -d -o root -g root -m 755 "$bridge_home/.ssh"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
printf 'restrict,command="/usr/local/libexec/mucho-vps-bridge" %s %s\n' \
  "$key_type" "$key_data" > "$tmp"
install -o root -g root -m 644 "$tmp" "$bridge_home/.ssh/authorized_keys"

install -d -o root -g root -m 755 /usr/local/libexec
install -o root -g root -m 755 \
  "$src_dir/vps-bridge-remote.sh" /usr/local/libexec/mucho-vps-bridge
install -o root -g root -m 755 \
  "$src_dir/vps-bridge-bench.py" /usr/local/libexec/mucho-vps-bridge-bench

echo "MuchoCore restricted bridge installed."
echo "SSH account: muchobridge (no sudo, no docker group)"
echo "Forced commands: status, smoke, bench (localhost:18080 only)"
echo "Configure GitHub repository secrets as documented in docs/VPS_BRIDGE.md."
