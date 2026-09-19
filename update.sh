#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
[[ $EUID -eq 0 ]] || { echo 'Запустите: sudo ./update.sh' >&2; exit 1; }

echo '[MuchoCore] Обновляю исходники...'
git fetch --depth=1 origin main
git reset --hard origin/main

echo '[MuchoCore] Пересобираю контейнеры...'
docker compose up -d --build --remove-orphans

echo '[MuchoCore] Обновляю PHP-зависимости...'
docker compose exec -T app composer install --no-dev --optimize-autoloader --no-interaction

echo '[MuchoCore] Применяю миграции...'
docker compose exec -T app php bin/migrate.php migrate

echo '[MuchoCore] Проверяю состояние...'
docker compose ps
