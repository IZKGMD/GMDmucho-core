#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

php -l public/deploy.php >/dev/null
php -l bin/mucho-deploy-worker.php >/dev/null
php -l bin/mucho-shared-deploy-worker.php >/dev/null

grep -Fq "function deployment_session_job_ok(string \$jobId): bool" public/deploy.php
grep -Fq "if (!deployment_session_job_ok(\$id))" public/deploy.php
grep -Fq "if (\$gdpsName === '' || mb_strlen(\$gdpsName, 'UTF-8') > 64" public/deploy.php
grep -Fq '"MUCHO_SERVER_NAME=" . shell_quote($gdpsName)' public/deploy.php

grep -Fq '\\MuchoCore\\Client\\DeploymentClientPack::prepare(' bin/mucho-shared-deploy-worker.php
! grep -Fq 'MuchoCoreClientDeploymentClientPack::prepare(' bin/mucho-shared-deploy-worker.php
grep -Fq "in_array(\$current, ['browser_completed', 'completed'], true)" bin/mucho-shared-deploy-worker.php
grep -Fq "'gdps_name' => (string)(\$config['gdps_name'] ?? 'Mucho GDPS')" bin/mucho-shared-deploy-worker.php
grep -Fq "remove_tree(\$dir . '/extracted');" bin/mucho-shared-deploy-worker.php
grep -Fq "read_json_file(\$statusFile)['status']" bin/mucho-shared-deploy-worker.php
! grep -Fq "status_read(\$statusFile)" bin/mucho-shared-deploy-worker.php
grep -Fq "shell_quote(\$installRef)" bin/mucho-deploy-worker.php
! grep -Fq "shell_quote(INSTALL_REF)" bin/mucho-deploy-worker.php

! grep -Fq "sessionStorage.setItem(AUTOSAVE_KEY+'_secret'" public/install/shared/index.html
! grep -Fq "sessionStorage.getItem(AUTOSAVE_KEY+'_secret')" public/install/shared/index.html
! grep -Fq "sessionStorage.setItem(AUTOSAVE_KEY+'_password'" public/deploy/index.html
! grep -Fq "sessionStorage.getItem(AUTOSAVE_KEY+'_password'" public/deploy/index.html
grep -Fq "const autosaveFields=['gdpsName','host','port','user','domain','adminUser'];" public/deploy/index.html

echo "deployment-contract: OK"
