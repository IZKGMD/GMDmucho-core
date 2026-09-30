#!/bin/sh
set -eu

METRICS_ADDR="${CLOUDFLARED_METRICS:-127.0.0.1:20241}"

cloudflared tunnel \
  --no-autoupdate \
  --protocol http2 \
  --metrics 0.0.0.0:20241 \
  run \
  --token "${MUCHO_TUNNEL_TOKEN}" &
PID="$!"

trap 'kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true' INT TERM EXIT

unready=0

while kill -0 "$PID" 2>/dev/null; do
  if cloudflared tunnel ready --metrics "$METRICS_ADDR" >/dev/null 2>&1; then
    unready=0
  else
    unready=$((unready + 1))
    if [ "$unready" -ge 4 ]; then
      echo "[MuchoCore] cloudflared lost all active connections; restarting the container." >&2
      kill "$PID" 2>/dev/null || true
      wait "$PID" 2>/dev/null || true
      trap - EXIT
      exit 1
    fi
  fi

  sleep 15
done

wait "$PID"
