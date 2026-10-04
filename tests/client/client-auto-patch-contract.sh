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
grep -Fq 'DeploymentClientPack::prepare' "$ROOT/bin/mucho-deploy-worker.php"
grep -Fq 'Generating clients for this GDPS' "$ROOT/bin/mucho-deploy-worker.php"
! grep -Fq 'MUCHO_SHARED_DEPLOY_SOURCE' "$ROOT/bin/mucho-deploy-worker.php"
! grep -Fq 'build-shared-hosting.sh' "$ROOT/bin/mucho-deploy-worker.php"
! test -e "$ROOT/bin/mucho-shared-deploy-worker.php"
! grep -Fq 'MUCHO_SHARED_DEPLOY_SOURCE' "$ROOT/docker-compose.yml"
grep -Fq 'client-patch.php' "$ROOT/bin/mucho"
grep -Fq 'client-patch.php' "$ROOT/update.sh"
grep -Fq '$zip = new \ZipArchive();' "$ROOT/src/Client/DeploymentClientPack.php"

echo "Auto Patch 2.0 contract passed"
