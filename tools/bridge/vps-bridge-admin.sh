#!/usr/bin/env bash
# Fixed privileged operations for the isolated MuchoCore stress stack.
# Installed root-owned at /usr/local/libexec/mucho-vps-admin.
# NEVER pass user-supplied paths, compose files, environment or shell snippets.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
unset BASH_ENV ENV CDPATH GIT_CONFIG_GLOBAL GIT_CONFIG_SYSTEM
umask 077

[[ "${EUID}" -eq 0 ]] || { echo "Must run through restricted sudo." >&2; exit 77; }
[[ "$#" -eq 1 ]] || { echo "Exactly one operation is required." >&2; exit 64; }

readonly OP="$1"
readonly DIR='/opt/mucho-stress'
readonly COMPOSE='/opt/mucho-stress/docker-compose.stress.yml'

case "$OP" in
  stress-status|stress-restart|stress-update) ;;
  *) echo "REJECTED: unsupported privileged operation." >&2; exit 64 ;;
esac

# Fail shut if the directory/file is replaced, symlinked or writable by
# non-root accounts. The Docker daemon has root-equivalent capabilities.
[[ -d "$DIR" && ! -L "$DIR" ]] ||
  { echo "Stress directory missing or symlinked." >&2; exit 66; }
[[ -f "$COMPOSE" && ! -L "$COMPOSE" ]] ||
  { echo "Stress compose file missing or symlinked." >&2; exit 66; }
[[ "$(stat -c %u "$DIR")" == "0" && "$(stat -c %u "$COMPOSE")" == "0" ]] ||
  { echo "Stress configuration must be root-owned." >&2; exit 77; }
mode_dir="$(stat -c %a "$DIR")"
mode_file="$(stat -c %a "$COMPOSE")"
for mode in "$mode_dir" "$mode_file"; do
  # Permissions are octal. Reject group/other write.
  if (( (8#$mode & 0022) != 0 )); then
    echo "Stress configuration is group/other-writable; refusing root operation." >&2
    exit 77
  fi
done
command -v docker >/dev/null || { echo "Docker unavailable." >&2; exit 69; }

cd -- "$DIR"
compose() { docker compose --project-directory "$DIR" -f "$COMPOSE" "$@"; }

case "$OP" in
  stress-status)
    echo "Privileged stress status (isolated environment only)"
    compose ps --services --status running
    ;;
  stress-restart)
    echo "Restarting ONLY the isolated stress app (production unaffected)"
    compose restart app
    compose ps --services --status running
    ;;
  stress-update)
    echo "Pulling and recreating ONLY the isolated stress app"
    compose pull app
    compose up -d --no-deps app
    compose ps --services --status running
    ;;
esac
