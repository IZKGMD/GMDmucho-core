#!/usr/bin/env bash
# Read-only native GD 2.209 comments probe. Never prints private comment text,
# cookies, credentials, IP addresses or raw response bodies.
set -Eeuo pipefail

base="${MUCHO_GDPS_URL:-https://muchogdps.space}"
level="${1:-152}"
[[ "$level" =~ ^[1-9][0-9]*$ ]] || { echo 'Level ID must be a positive integer' >&2; exit 2; }
[[ "$base" =~ ^https://[A-Za-z0-9.-]+$ ]] || {
  echo 'MUCHO_GDPS_URL must be an HTTPS origin without a path' >&2; exit 2;
}
command -v curl >/dev/null || { echo 'curl is required' >&2; exit 2; }
command -v python3 >/dev/null || { echo 'python3 is required' >&2; exit 2; }

tmp="$(mktemp)"
chmod 600 "$tmp"
trap 'rm -f "$tmp"' EXIT

printf 'GD 2.209 guest comment smoke for level %s\n' "$level"
if ! timing="$(curl --silent --show-error --location --max-redirs 2 \
    --connect-timeout 5 --max-time 20 \
    --request POST "$base/database/getGJComments21.php" \
    --data-urlencode "levelID=$level" \
    --data 'page=0&count=10&total=0&mode=0&cType=0&gameVersion=22&binaryVersion=51&secret=Wmfd2893gb7' \
    --output "$tmp" \
    --write-out 'HTTP=%{http_code} bytes=%{size_download} total_seconds=%{time_total}')"; then
  echo 'TRANSPORT_FAILED: request did not finish; inspect Caddy/PHP logs'
  exit 1
fi
printf '%s\n' "$timing"

python3 - "$tmp" <<'PY'
import base64
import re
import sys

with open(sys.argv[1], "rb") as fh:
    body = fh.read().decode("utf-8", "replace").strip()

if body == "-2":
    print("WIRE=NO_COMMENTS (valid empty result)")
    sys.exit(0)
if body in ("-1", ""):
    print("WIRE=FAILED (check PHP error log for [MuchoCore CommentRead])")
    sys.exit(1)

header, mark, trailer = body.rpartition("#")
match = re.fullmatch(r"(\d+):(\d+):(\d+)", trailer)
if not mark or not match:
    print("WIRE=INVALID pagination trailer")
    sys.exit(1)

entries = header.split("|")
if not 1 <= len(entries) <= 10:
    print("WIRE=INVALID comment count")
    sys.exit(1)

for entry in entries:
    parts = entry.split(":", 1)
    match_content = re.search(r"(?:^|~)2~([A-Za-z0-9_+/=-]+)", parts[0])
    if len(parts) != 2 or match_content is None:
        print("WIRE=INVALID comment/user structure")
        sys.exit(1)
    encoded = match_content.group(1).replace("-", "+").replace("_", "/")
    try:
        decoded = base64.b64decode(encoded + "=" * (-len(encoded) % 4), validate=True)
        decoded.decode("utf-8")
    except (ValueError, UnicodeError, base64.binascii.Error):
        print("WIRE=INVALID Base64 comment content")
        sys.exit(1)

print("WIRE=OK total=%s offset=%s page_size=%s entries=%d" %
      (match.group(1), match.group(2), match.group(3), len(entries)))
PY
