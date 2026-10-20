#!/usr/bin/env bash
# Runs ONLY in an ephemeral GitHub Actions environment, not on a VPS.
set -Eeuo pipefail
if [[ "$GITHUB_ACTIONS" != "true" || "$RUNNER_ENVIRONMENT" != "github-hosted" ]]; then
  echo "Refusing A/B setup outside GitHub-hosted runner" >&2
  exit 2
fi
if [[ ! -f "$GITHUB_WORKSPACE/baseline/composer.json" || ! -f "$GITHUB_WORKSPACE/candidate/composer.json" ]]; then
  echo "Missing baseline or candidate checkout" >&2
  exit 2
fi

BASE="$GITHUB_WORKSPACE/baseline"
CAND="$GITHUB_WORKSPACE/candidate"
USER_NAME="$(id -un)"
GROUP_NAME="$(id -gn)"

# Ephemeral, synthetic database credentials known only to this CI job.
for ROOT in "$BASE" "$CAND"; do
  cat > "$ROOT/.env" <<'ENVFILE'
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=bench
DB_USER=bench
DB_PASS=bench-password
MUCHO_PROTECT=1
MUCHO_PROTECT_STORAGE=file
MUCHO_CACHE_DRIVER=database
MUCHO_LEVEL_CACHE_TTL=15
MUCHO_GD_VERSIONS=all
MUCHO_CLIENT_TRACE=0
ENVFILE
  mkdir -p "$ROOT/storage/control/rate-limit" "$ROOT/storage/control/protect-penalties"
done

(
  cd "$BASE"
  php bin/migrate.php migrate > /tmp/muchocore-ci-migrations.log 2>&1 || {
    tail -100 /tmp/muchocore-ci-migrations.log >&2
    exit 1
  }
)

# Fixture is deliberately ONE level, matching current VPS cardinality.
# All values are fabricated and never originate from a real user's GDPS.
(
  cd "$BASE"
  php -r '
    require "vendor/autoload.php";
    $db = (new \MuchoCore\Database\Database())->connection();
    $db->exec("INSERT INTO accounts
      (account_id, username, email, password_hash)
      VALUES (1, '\''BenchRobot'\'', '\''bench@example.invalid'\'', '\''disabled'\'')");
    $db->exec("INSERT INTO profiles (account_id) VALUES (1)");
    $db->exec("INSERT INTO levels
      (level_id,account_id,name,description,level_data,game_version)
      VALUES (1,1,'\''Benchmark Level'\'','\''Synthetic data only'\'','\''1,2,3;'\'' ,22)");
    echo "PASS: one synthetic visible level seeded\n";
  '
)

# Each PHP-FPM pool loads a different source checkout. Both connect to the
# same isolated DB; neither uses the VPS' Docker Compose stack.
cat <<EOF | sudo tee /etc/php/8.3/fpm/pool.d/muchocore-base.conf >/dev/null
[muchocore_base]
user = $USER_NAME
group = $GROUP_NAME
listen = 127.0.0.1:19001
pm = static
pm.max_children = 2
clear_env = no
catch_workers_output = yes
php_admin_value[log_errors] = on
php_admin_value[display_errors] = off
EOF
cat <<EOF | sudo tee /etc/php/8.3/fpm/pool.d/muchocore-cand.conf >/dev/null
[muchocore_candidate]
user = $USER_NAME
group = $GROUP_NAME
listen = 127.0.0.1:19002
pm = static
pm.max_children = 2
clear_env = no
catch_workers_output = yes
php_admin_value[log_errors] = on
php_admin_value[display_errors] = off
EOF

# No external listener. The FastCGI script is always public/index.php, as
# with MuchoCore's production Caddy rewrite/fallback routing.
cat <<EOF | sudo tee /etc/nginx/conf.d/muchocore-ab.conf >/dev/null
server {
    listen 127.0.0.1:18081;
    server_name localhost;
    root $BASE/public;
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $BASE/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass 127.0.0.1:19001;
    }
}
server {
    listen 127.0.0.1:18082;
    server_name localhost;
    root $CAND/public;
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $CAND/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass 127.0.0.1:19002;
    }
}
EOF

sudo php-fpm8.3 -t
sudo service php8.3-fpm restart
sudo nginx -t
sudo service nginx restart
for PORT in 18081 18082; do
  for TRY in 1 2 3 4 5 6 7 8 9 10; do
    if [[ "$(curl --max-time 3 -sS "http://127.0.0.1:$PORT/health")" == "1" ]]; then
      break
    fi
    sleep 1
  done
  if [[ "$(curl --max-time 3 -sS "http://127.0.0.1:$PORT/health")" != "1" ]]; then
    echo "Failed health check on $PORT" >&2
    exit 1
  fi
done
echo "PASS: isolated Nginx + two PHP-FPM pools and shared fixture database"
