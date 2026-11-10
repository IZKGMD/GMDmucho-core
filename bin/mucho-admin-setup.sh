#!/usr/bin/env bash
# Local bootstrap secret for new installs; explicit CLI password reset for running installs.
# No secret is passed as a command-line argument, printed or sent to GitHub.
set -Eeuo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run with sudo: sudo bin/mucho-admin-setup.sh" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
[[ -f docker-compose.yml ]] || { echo "Missing docker-compose.yml" >&2; exit 1; }
[[ -r /dev/tty && -w /dev/tty ]] || {
  echo "A local interactive terminal is required. Do not put passwords in automation logs." >&2
  exit 1
}

umask 077
install -d -m 700 .secrets
if [[ -s .secrets/admin_password ]]; then
  echo "The admin secret already exists. This will replace it, not reset the GDPS database."
else
  echo "First-time MuchoCore setup. Choose a setup secret for the /admin/ browser wizard."
fi

first=""
second=""
valid=0
for attempt in 1 2 3; do
  read -r -s -p "Setup secret / explicit reset password (at least 10 characters): " first < /dev/tty || exit 1
  printf '\n'
  read -r -s -p "Confirm admin password: " second < /dev/tty || exit 1
  printf '\n'
  if [[ "${#first}" -lt 10 ]]; then
    echo "Password is too short." >&2
  elif [[ "$first" != "$second" ]]; then
    echo "Passwords do not match." >&2
  elif [[ "$first" == *$'\n'* || "$first" == *$'\r'* ]]; then
    echo "Password must not contain newlines." >&2
  else
    valid=1
    break
  fi
done
[[ "$valid" == 1 ]] || { echo "No valid admin password entered." >&2; exit 1; }

tmp="$(mktemp .secrets/admin_password.XXXXXX)"
trap 'rm -f "$tmp"' EXIT
printf '%s' "$first" > "$tmp"
chmod 600 "$tmp"
unset first second
mv -f "$tmp" .secrets/admin_password
trap - EXIT
echo "Administrator secret saved with permissions 0600. No password has been printed."

# For existing manual Docker installs, re-create only the PHP app so Docker
# secrets are remounted, then synchronize the admin_users hash. Never replace
# the DB volume and never build or pull images during a password change.
if [[ -f .env ]] && command -v docker >/dev/null 2>&1; then
  compose=( -f docker-compose.yml )
  [[ -f compose.public.yaml ]] && compose+=( -f compose.public.yaml )
  if [[ -n "$(docker compose "${compose[@]}" ps -q app 2>/dev/null || true)" ]]; then
    echo "Applying the new password to the running PHP application..."
    docker compose "${compose[@]}" up -d --no-deps --no-build --force-recreate app
    docker compose "${compose[@]}" exec -T app php bin/mucho-sync-admin.php --reset-password
    if [[ -n "$(docker compose "${compose[@]}" ps -q caddy 2>/dev/null || true)" ]]; then
      docker compose "${compose[@]}" exec -T caddy \
        caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    fi
    echo "Admin password applied; existing GDPS accounts and levels were kept."
  else
    echo "App is not running yet. Start it after the initial installation."
  fi
else
  echo "Docker deployment is not ready. The installer will use the saved secret."
fi

echo "Administrator username: admin (unless ADMIN_USER was customized in .env)"
echo "Fresh install: open HTTPS /admin/ and choose your FINAL owner login password using the setup secret above."
