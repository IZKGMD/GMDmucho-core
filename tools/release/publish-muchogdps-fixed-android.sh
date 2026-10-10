#!/usr/bin/env bash
set -Eeuo pipefail

# Restore a verified 2.2081 APK on the VPS, publishing ONE direct download.
# The Drive folder must temporarily be shared as Anyone with the link / Viewer.
ROOT="${MUCHO_ROOT:-/opt/mucho-core}"
DEST="$ROOT/releases/android"
FILE="GeometryDash-2.2081-MuchoGDPS-Fixed.apk"
EXPECTED_APK="e7c15e4d3b20e504bba14ee5fa779ff97b9320f4a178d6a17094101517f74883"
EXPECTED_ZIP="2ceb959edc459d302fbbc9bef4fca9ecf6555a41727a8efcca7acba065597f38"

if [[ ! -f "$ROOT/docker/Caddyfile" ]]; then
  echo "ERROR: MuchoCore not found under $ROOT" >&2
  exit 1
fi

mkdir -p "$DEST"
chmod 755 "$DEST"
workdir="$(mktemp -d "$DEST/.apk-stage.XXXXXXXX")"
trap 'rm -rf -- "$workdir"' EXIT
archive="$workdir/archive.zip"
: > "$archive"
count=0

while read -r drive_id expected_hash; do
  part="$workdir/part"
  printf '[%d/9] Fetching source data...\n' "$((count+1))"
  if [[ -n "${MUCHO_DRIVE_PARTS_DIR:-}" ]]; then
    cp -- "$MUCHO_DRIVE_PARTS_DIR/MuchoGDPS-Fixed.apk.zip.$(printf '%03d' "$count")" "$part"
  else
    curl --fail --location --silent --show-error \
      --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 600 \
      "https://drive.usercontent.google.com/download?id=${drive_id}&export=download&confirm=t" \
      --output "$part"
  fi
  if ! printf '%s  %s\n' "$expected_hash" "$part" | sha256sum --check --status; then
    echo 'ERROR: Drive returned wrong data, possibly a sign-in page.' >&2
    echo 'Set folder sharing: Anyone with the link -> Viewer and retry.' >&2
    exit 1
  fi
  cat "$part" >> "$archive"
  rm -f "$part"
  count=$((count+1))
done <<'DRIVE_PARTS'
1FwSGXnASFo-LVDXtmt_r8UJ8BOmjpY1g 017e6c1e3fb7866a76a1efe232d5403d268ee8be1e5afaab79bd4858a487905a
1jHjxn1wuU8xvtTRTnD6Pn3mVflS9VAPF 54e5ca978f053d74a0fb967ee3511edd564d4461f701d28dddb07256fa575f90
1mgMLZECLP_RBp9xiplJ_G2aFmoZCtYq3 95bf3560e090a3765c20a6c9d44858efbb7911315b6a0c6d2e0b1ae8fe29cde8
1F-749HeukzULIMQFdx8VY50V-XfMcuu_ 686e9f7ca2beeaf6b35b34ebbf7496e3326d96255ebad6e7b07b1fcde5bbe65f
1Sg4kp9inDSiiW1nAxbZ13nTGVJDQx5Et 672dabdce2fac3f4321088f6aaa2125bf15ce8ae3f35cfc18a65305c8a9eaad8
1eOi7K5467ZBbNJBlRTH0tX_6mu9Ee1hn 53f6903d549a0883704f7f4c7671d64703bda6aa2dc700e5d951540ec04e4bed
17FR71V2uH-zifLUjy69UjKv1GfLmC4Gl cfbaebde9943b4f78e876d331c8e90467e3a4fb8743a2bcf5cc0e770ea176fad
1-Zjc04bwR3_sIqHGkud23487zeT_q_Bl 1d6b704bef7541c5940e57841bd5c9a924a085547d24c401331e567b1a5e7f47
11pn7AYA9mscV8yUpjff6gD5tcbD6uuX5 2e4363e0695045abc00a2422b2e54e53c2d3a0352f5a4e1d3af7f78ee8d6c061
DRIVE_PARTS

if [[ "$count" != 9 ]] || ! printf '%s  %s\n' "$EXPECTED_ZIP" "$archive" | sha256sum --check --status; then
  echo 'ERROR: Reconstructed ZIP checksum mismatch.' >&2
  exit 1
fi
unzip -p "$archive" "$FILE" > "$workdir/$FILE"
if ! printf '%s  %s\n' "$EXPECTED_APK" "$workdir/$FILE" | sha256sum --check --status; then
  echo 'ERROR: APK checksum mismatch; refusing to publish.' >&2
  exit 1
fi
chmod 644 "$workdir/$FILE"
mv -f -- "$workdir/$FILE" "$DEST/$FILE"
echo 'APK_PUBLISHED_OK'
echo "SHA256=$EXPECTED_APK"
echo "FILE=$DEST/$FILE"
echo "URL=https://muchogdps.space/downloads/android/$FILE"
