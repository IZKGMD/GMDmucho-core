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
command -v php >/dev/null || { echo 'php is required' >&2; exit 2; }

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

php -r '
$body = file_get_contents($argv[1]);
if ($body === false) exit(1);
$body = trim($body);
if ($body === "-2") {
    echo "WIRE=NO_COMMENTS (valid empty result)\n";
    exit(0);
}
if ($body === "-1" || $body === "") {
    echo "WIRE=FAILED (check PHP error log for [MuchoCore CommentRead])\n";
    exit(1);
}
if (!preg_match("/#(\\d+):(\\d+):(\\d+)$/D", $body, $matches)) {
    echo "WIRE=INVALID pagination trailer\n";
    exit(1);
}
$parts = explode("#", $body, 2);
$entries = explode("|", $parts[0]);
if (count($entries) > 10 || count($entries) === 0) {
    echo "WIRE=INVALID comment count\n";
    exit(1);
}
foreach ($entries as $entry) {
    $segments = explode(":", $entry, 2);
    if (!isset($segments[1]) ||
        preg_match("/(?:^|~)2~([A-Za-z0-9_+\\/=-]+)/", $segments[0], $comment) !== 1) {
        echo "WIRE=INVALID comment/user structure\n";
        exit(1);
    }
    $encoded = strtr($comment[1], "-_", "+/");
    if (base64_decode($encoded, true) === false) {
        echo "WIRE=INVALID Base64 comment content\n";
        exit(1);
    }
}
echo "WIRE=OK total=" . $matches[1] . " offset=" . $matches[2] .
    " page_size=" . $matches[3] . " entries=" . count($entries) . "\n";
' "$tmp"
