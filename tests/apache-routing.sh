#!/usr/bin/env bash
set -euo pipefail

# Keep private project folders blocked.
grep -Fq 'RewriteRule ^(?:\.git|config|src|tests|tools|vendor|storage)' .htaccess

# /database is intentionally public-facing for Geometry Dash compatibility.
! grep -Fq 'RewriteRule ^(?:\.git|config|src|database|tests|tools|vendor|storage)' .htaccess

# These two shared-hosting routes must remain available.
grep -Fq 'RewriteRule ^shared-install\.php$ public/shared-install.php [END]' .htaccess
grep -Fq 'RewriteRule ^(.*)$ public/$1 [L]' .htaccess

echo "MUCHOCORE_APACHE_ROUTING_OK"
