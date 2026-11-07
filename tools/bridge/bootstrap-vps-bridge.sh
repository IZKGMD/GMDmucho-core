#!/usr/bin/env bash
# One-command MuchoCore VPS bridge installer. One-time GitHub device login.
set -Eeuo pipefail
export PATH=/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin
umask 077

REPO='IZKGMD/GMDmucho-core'
BASE='https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/tools/bridge'
DIR='/root/mucho-bridge-setup'
KEY="$DIR/bridge_ed25519"
fail() { echo "ERROR: $*" >&2; exit 1; }
step() { echo; echo "[MuchoCore VPS bridge] $*"; }

(( EUID == 0 )) || fail 'Run this installer with sudo or as root.'
[[ -t 0 ]] || fail 'Run from an interactive SSH terminal for GitHub device authorization.'
command -v curl >/dev/null || fail 'curl is required.'
command -v ssh-keygen >/dev/null || fail 'OpenSSH tools are required.'
command -v python3 >/dev/null || fail 'Python 3 is required.'
[[ ! -L "$DIR" ]] || fail 'Refusing symlinked setup directory.'
install -d -o root -g root -m 700 "$DIR"

step 'Downloading bridge helpers from the MuchoCore main branch.'
for name in install-vps-bridge.sh vps-bridge-remote.sh vps-bridge-bench.py; do
  curl -fsSL --retry 2 --connect-timeout 10 --max-time 30 "$BASE/$name" -o "$DIR/$name"
done
bash -n "$DIR/install-vps-bridge.sh"
bash -n "$DIR/vps-bridge-remote.sh"
python3 -m py_compile "$DIR/vps-bridge-bench.py"

if [[ ! -e "$KEY" && ! -e "$KEY.pub" ]]; then
  step 'Creating a restricted, dedicated SSH key.'
  ssh-keygen -q -t ed25519 -N '' -f "$KEY" -C 'muchocore-github-actions'
elif [[ ! -f "$KEY" || ! -f "$KEY.pub" ]]; then
  fail 'Incomplete key pair: inspect /root/mucho-bridge-setup before retrying.'
fi
chmod 600 "$KEY"
chmod 644 "$KEY.pub"
ssh-keygen -lf "$KEY.pub" >/dev/null

step 'Creating the unprivileged, forced-command SSH account.'
bash "$DIR/install-vps-bridge.sh" "$KEY.pub"

step 'Detecting SSH address and verifying the host key.'
host="${MUCHO_BRIDGE_HOST:-}"
if [[ -z "$host" ]]; then
  host="$(curl -fsSL --noproxy '*' --connect-timeout 5 --max-time 10 https://api.ipify.org)" ||
    fail 'Public IPv4 detection failed. Set MUCHO_BRIDGE_HOST and retry.'
fi
python3 - "$host" <<'PY' || fail 'Invalid public VPS hostname/address.'
import ipaddress, re, sys
s = sys.argv[1]
try:
    ipaddress.ip_address(s)
except ValueError:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,251}[A-Za-z0-9]", s):
        raise SystemExit(1)
PY
port="${MUCHO_BRIDGE_PORT:-}"
if [[ -z "$port" ]]; then
  port="$(sshd -T 2>/dev/null | awk '$1=="port" {print $2; exit}' || true)"
  port="${port:-22}"
fi
if ! [[ "$port" =~ ^[0-9]{1,5}$ ]] || (( 10#$port < 1 || 10#$port > 65535 )); then
  fail 'Invalid SSH port.'
fi
pub=''
for candidate in /etc/ssh/ssh_host_ed25519_key.pub /etc/ssh/ssh_host_rsa_key.pub; do
  if [[ -s "$candidate" ]]; then pub="$candidate"; break; fi
done
[[ -n "$pub" ]] || fail 'Missing /etc/ssh/ SSH host public key.'
read -r keytype keydata _ < "$pub"
if [[ "$port" == 22 ]]; then host_label="$host"; else host_label="[$host]:$port"; fi
host_line="$host_label $keytype $keydata"

if ! command -v gh >/dev/null 2>&1; then
  step 'Installing GitHub CLI.'
  command -v apt-get >/dev/null || fail 'Install gh (GitHub CLI), then retry.'
  DEBIAN_FRONTEND=noninteractive apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq gh
fi
new_login=0
if ! gh auth status -h github.com >/dev/null 2>&1; then
  step 'One-time GitHub authorization. Use the code displayed below at github.com/login/device.'
  gh auth login -h github.com -p https -s repo,workflow --web ||
    fail 'GitHub authorization incomplete. Retry this installer.'
  new_login=1
fi
actual="$(gh api user --jq '.login' 2>/dev/null || true)"
[[ "$actual" == 'IZKGMD' ]] || fail 'Connected GitHub user is not IZKGMD.'
is_admin="$(gh api "repos/$REPO" --jq '.permissions.admin' 2>/dev/null || true)"
[[ "$is_admin" == 'true' ]] || fail 'GitHub user does not have admin rights on MuchoCore.'

step 'Configuring five encrypted GitHub Actions secrets.'
printf '%s' "$host" | gh secret set VPS_BRIDGE_HOST -R "$REPO"
printf '%s' "$port" | gh secret set VPS_BRIDGE_PORT -R "$REPO"
printf '%s' 'muchobridge' | gh secret set VPS_BRIDGE_USER -R "$REPO"
gh secret set VPS_BRIDGE_PRIVATE_KEY -R "$REPO" < "$KEY"
printf '%s\n' "$host_line" | gh secret set VPS_BRIDGE_KNOWN_HOSTS -R "$REPO"

if (( new_login == 1 )); then
  gh auth logout -h github.com >/dev/null 2>&1 || true
fi
step 'READY. Open https://github.com/IZKGMD/GMDmucho-core/issues/205'
echo 'Ask ChatGPT to run /vps status. No production files or database changed.'
echo 'Note: SSH must be reachable from GitHub Actions; NAT/firewall rules are not changed.'
