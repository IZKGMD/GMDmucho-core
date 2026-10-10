#!/usr/bin/env bash
set -Eeuo pipefail

# Regression: the old entrypoint left storage/control as root:root 0700.
# MuchoProtect's allowStrict() then failed to open buckets for www-data
# and every public endpoint returned "-1" with global_rate_limit.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[[ $EUID -eq 0 ]] || {
    echo "Run this test as root (CI: sudo bash ...)" >&2
    exit 1
}
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
chmod 755 "$tmp"
mkdir -p "$tmp/storage/control/rate-limit" "$tmp/storage/control/protect-penalties"
chmod 700 "$tmp/storage/control"
printf '{"start":1,"count":1}' > "$tmp/storage/control/rate-limit/old-bucket.json"
printf '{"expires":1}' > "$tmp/storage/control/protect-penalties/old-penalty.json"
printf 'keep-flag' > "$tmp/storage/control/maintenance.flag"

bash "$ROOT/docker/init-protect-storage.sh" "$tmp"
bash "$ROOT/docker/init-protect-storage.sh" "$tmp" # must be safe to rerun

[[ "$(stat -c '%U:%G:%a' "$tmp/storage/control")" == "root:www-data:750" ]]
for name in rate-limit protect-penalties; do
    [[ "$(stat -c '%U:%G:%a' "$tmp/storage/control/$name")" == "www-data:www-data:700" ]]
done
[[ "$(stat -c '%U' "$tmp/storage/control/maintenance.flag")" == "root" ]]
[[ "$(cat "$tmp/storage/control/maintenance.flag")" == "keep-flag" ]]
[[ "$(stat -c '%U' "$tmp/storage/control/rate-limit/old-bucket.json")" == "www-data" ]]
[[ "$(stat -c '%U' "$tmp/storage/control/protect-penalties/old-penalty.json")" == "www-data" ]]

# This must execute as the *actual* PHP account, not root; it is the
# production failure mode that source-level string tests would miss.
runuser -u www-data -- php -r '
$root = $argv[1];
foreach (["rate-limit", "protect-penalties"] as $directory) {
    $path = $root . "/storage/control/" . $directory . "/write-test";
    if (file_put_contents($path, "OK") !== 2 || !unlink($path)) {
        exit(1);
    }
}
' "$tmp"
echo "MUCHOPROTECT_STORAGE_INIT_OK"
