#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

php -l "$ROOT/src/Client/WindowsClientPatcher.php" >/dev/null
php -l "$ROOT/src/Client/AndroidClientPatcher.php" >/dev/null
php -l "$ROOT/src/Client/DeploymentClientPack.php" >/dev/null
php -l "$ROOT/bin/mucho-client-patch.php" >/dev/null

grep -Fq "'www.geometrydash.com'" "$ROOT/src/Client/WindowsClientPatcher.php"
grep -Fq "'geometrydash.com'" "$ROOT/src/Client/WindowsClientPatcher.php"
grep -Fq "'www.geometrydash.com'" "$ROOT/src/Client/AndroidClientPatcher.php"
grep -Fq "'geometrydash.com'" "$ROOT/src/Client/AndroidClientPatcher.php"

grep -Fq 'replaceNullTerminatedUrlHost' "$ROOT/src/Client/WindowsClientPatcher.php"
grep -Fq 'replaceNullTerminatedUtf16Host' "$ROOT/src/Client/WindowsClientPatcher.php"
grep -Fq 'replaceBase64EmbeddedHost' "$ROOT/src/Client/WindowsClientPatcher.php"
grep -Fq 'databas/checkIfServerOnline.php' "$ROOT/src/Client/WindowsClientPatcher.php"

grep -Fq 'replaceNullTerminatedUrlHost' "$ROOT/src/Client/AndroidClientPatcher.php"
grep -Fq 'replaceNullTerminatedUtf16Host' "$ROOT/src/Client/AndroidClientPatcher.php"
grep -Fq 'replaceBase64EmbeddedHost' "$ROOT/src/Client/AndroidClientPatcher.php"
grep -Fq 'databas/checkIfServerOnline.php' "$ROOT/src/Client/AndroidClientPatcher.php"

grep -Fq "'patch_engine' => '2.0'" "$ROOT/src/Client/DeploymentClientPack.php"
grep -Fq 'MUCHO_ACCOUNT_URL' "$ROOT/bin/mucho-client-patch.php"
grep -Fq 'client-patch.php' "$ROOT/bin/mucho"
grep -Fq 'client-patch.php' "$ROOT/update.sh"
grep -Fq '$zip = new \ZipArchive();' "$ROOT/src/Client/DeploymentClientPack.php"

if grep -rIl $'\x00' "$ROOT/src/Client" >/tmp/muchocore-client-nul-files 2>/dev/null; then
    echo "Client patcher source contains embedded NUL bytes." >&2
    cat /tmp/muchocore-client-nul-files >&2
    exit 1
fi

echo "Auto Patch 2.0 contract passed"
