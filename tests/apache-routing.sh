#!/usr/bin/env bash
set -euo pipefail

# The root .htaccess serves two jobs:
# 1) protect private project folders;
# 2) rewrite public requests into public/.
#
# /database must stay reachable because older Geometry Dash clients use it.

grep -Eq '^RewriteRule \^\(\?:\\\.git\|config\|src\|tests\|tools\|vendor\|storage\)' .htaccess
! grep -Eq '^RewriteRule \^\(\?:.*\|database\|' .htaccess

grep -Fq 'RewriteRule ^shared-install\.php$ public/shared-install.php [END]' .htaccess
grep -Fq 'RewriteRule ^(.*)$ public/$1 [L]' .htaccess

echo "MUCHOCORE_APACHE_ROUTING_OK"
