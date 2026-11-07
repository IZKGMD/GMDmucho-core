#!/usr/bin/env bash
# Installed root-owned at /usr/local/libexec/mucho-vps-bridge.
# Invoked only via an OpenSSH authorized_keys forced command, as unprivileged
# 'muchobridge'. Nothing here invokes Docker, sudo, a shell from input, or
# code from the Git checkout. Only three stress commands invoke a separate
# root-owned helper via sudoers entries matching exact command arguments.
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/bin
umask 077

readonly STRESS_ORIGIN='http://127.0.0.1:18080'
readonly REQUEST="${SSH_ORIGINAL_COMMAND:-}"

# Reject direct SSH logins and *all* commands not explicitly supported.
case "$REQUEST" in
  status|smoke|bench|gd2209|stress-status|stress-restart|stress-update) ;;
  *) printf 'REJECTED: unsupported bridge command\n' >&2; exit 64 ;;
esac

if [[ -n "${SSH_TTY:-}" ]]; then
  printf 'REJECTED: interactive sessions are not supported\n' >&2
  exit 65
fi

probe_http() {
  local path="$1" method="$2"
  local status
  if [[ "$method" == "POST" ]]; then
    status="$(curl --silent --show-error --noproxy '*' --max-time 6 \
      -o /dev/null -w '%{http_code}' \
      -X POST --data 'gameVersion=22&binaryVersion=51&str=__muchocore_bridge_nonexistent__&page=0' \
      "$STRESS_ORIGIN$path")" || return 1
  else
    status="$(curl --silent --show-error --noproxy '*' --max-time 6 \
      -o /dev/null -w '%{http_code}' "$STRESS_ORIGIN$path")" || return 1
  fi
  printf '%s %s -> HTTP %s\n' "$method" "$path" "$status"
  [[ "$status" =~ ^2[0-9][0-9]$ ]]
}

case "$REQUEST" in
  status)
    printf 'MuchoCore bridge status (read-only)\n'
    printf 'Uptime: '
    uptime -p || uptime
    printf 'Memory (MiB):\n'
    free -m | head -n 3
    printf 'Root disk:\n'
    df -h /
    printf 'Isolated stress server:\n'
    probe_http /health GET || {
      printf 'Local stress service is not healthy or is unreachable.\n'
      exit 2
    }
    ;;
  smoke)
    printf 'MuchoCore isolated stress smoke — NOT a real game-client certification\n'
    body="$(curl --fail --silent --show-error --noproxy '*' --max-time 6 "$STRESS_ORIGIN/health")" || {
      printf 'Local stress health request failed\n'
      exit 2
    }
    if [[ "$body" != "1" ]]; then
      printf 'Unexpected /health body (expected 1)\n'
      exit 3
    fi
    printf 'GET /health -> 1\n'
    probe_http /checkIfServerOnline.php GET
    probe_http /a/getGJLevels21.php POST
    printf 'These checks validate routing and HTTP status, not successful GD protocol responses.\n'
    ;;
  bench)
    printf 'Bounded health-only localhost benchmark (40 requests, sequential)\n'
    exec python3 /usr/local/libexec/mucho-vps-bridge-bench
    ;;
  gd2209)
    printf 'Geometry Dash 2.209 synthetic read-only protocol probe\n'
    exec python3 /usr/local/libexec/mucho-vps-bridge-gd2209
    ;;
  stress-status|stress-restart|stress-update)
    # Only three explicit sudoers commands are available to muchobridge.
    exec sudo -n -- /usr/local/libexec/mucho-vps-admin "$REQUEST"
    ;;
esac
