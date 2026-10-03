#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

php -l "$ROOT/src/Client/WindowsClientPatcher.php" >/dev/null
php -l "$ROOT/src/Client/AndroidClientPatcher.php" >/dev/null
php -l "$ROOT/src/Client/DeploymentClientPack.php" >/dev/null
php -l "$ROOT/src/Database/Database.php" >/dev/null
php -l "$ROOT/public/admin/index.php" >/dev/null
php -l "$ROOT/public/admin/pages/players.php" >/dev/null
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
grep -Fq 'MUCHO_SHARED_DEPLOY_SOURCE' "$ROOT/bin/mucho-shared-deploy-worker.php"
grep -Fq 'build-shared-hosting.sh' "$ROOT/bin/mucho-shared-deploy-worker.php"
grep -Fq 'MUCHO_SHARED_DEPLOY_SOURCE' "$ROOT/docker-compose.yml"
grep -Fq 'client-patch.php' "$ROOT/bin/mucho"
grep -Fq 'client-patch.php' "$ROOT/update.sh"
grep -Fq '$zip = new \ZipArchive();' "$ROOT/src/Client/DeploymentClientPack.php"

if grep -rIl $'\x00' "$ROOT/src/Client" >/tmp/muchocore-client-nul-files 2>/dev/null; then
    echo "Client patcher source contains embedded NUL bytes." >&2
    cat /tmp/muchocore-client-nul-files >&2
    exit 1
fi

worker_clients_line="$(grep -n 'Generating clients for this GDPS' "$ROOT/bin/mucho-shared-deploy-worker.php" | head -1 | cut -d: -f1)"
worker_wait_line="$(grep -n 'Waiting for browser finalization' "$ROOT/bin/mucho-shared-deploy-worker.php" | head -1 | cut -d: -f1)"
if [[ -z "$worker_clients_line" || -z "$worker_wait_line" || "$worker_clients_line" -ge "$worker_wait_line" ]]; then
    echo "Shared worker must upload patched clients before browser finalization." >&2
    exit 1
fi

echo "Auto Patch 2.0 contract passed"

grep -Fq 'id="adminPasswordConfirm"' "$ROOT/public/install/shared/index.html"
grep -Fq 'admin_password_confirm' "$ROOT/public/install/shared/index.html"
grep -Fq 'admin_password_confirm' "$ROOT/public/deploy.php"
grep -Fq 'Admin passwords do not match.' "$ROOT/public/deploy.php"

grep -Fq 'LOCK_EX | LOCK_NB' "$ROOT/public/deploy.php"
grep -Fq "Deployment start timed out." "$ROOT/public/install/shared/index.html"
