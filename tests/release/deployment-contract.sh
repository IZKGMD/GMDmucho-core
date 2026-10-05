#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

php -l public/deploy.php >/dev/null
php -l bin/mucho-deploy-worker.php >/dev/null

grep -Fq "function deployment_session_job_ok(string \$jobId): bool" public/deploy.php
grep -Fq "function deployment_start_lock" public/deploy.php
grep -Fq "\$startLock = deployment_start_lock();" public/deploy.php
grep -Fq "if (!deployment_session_job_ok(\$id))" public/deploy.php
grep -Fq "if (\$gdpsName === '' || mb_strlen(\$gdpsName, 'UTF-8') > 64" public/deploy.php
grep -Fq '"MUCHO_SERVER_NAME=" . shell_quote($gdpsName)' public/deploy.php

grep -Fq '\MuchoCore\Client\DeploymentClientPack::prepare(' bin/mucho-deploy-worker.php
grep -Fq -- '--dispatch-queue' bin/mucho-deploy-worker.php
grep -Fq 'function deployment_dispatch_next' bin/mucho-deploy-worker.php
grep -Fq 'MUCHO_DEPLOY_MAX_CONCURRENT' bin/mucho-deploy-worker.php
grep -Fq 'dispatch.lock' bin/mucho-deploy-worker.php
grep -Fq "\$status['status'] = 'running'" bin/mucho-deploy-worker.php
! grep -Fq 'MuchoCoreClientDeploymentClientPack::prepare(' bin/mucho-deploy-worker.php
grep -Fq "shell_quote(\$installRef)" bin/mucho-deploy-worker.php
! grep -Fq "shell_quote(INSTALL_REF)" bin/mucho-deploy-worker.php

! grep -Fq "sessionStorage.setItem(AUTOSAVE_KEY+'_password'" public/deploy/index.html
! grep -Fq "sessionStorage.getItem(AUTOSAVE_KEY+'_password'" public/deploy/index.html
grep -Fq "const autosaveFields=['gdpsName','host','port','user','domain','adminUser'];" public/deploy/index.html

echo "deployment-contract: OK"
