#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${MUCHO_REPO_URL:-https://github.com/andrey888787/GMDmucho-core.git}"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
ADMIN_USER="${MUCHO_ADMIN_USER:-admin}"
DB_NAME="${MUCHO_DB_NAME:-muchocore}"
DB_USER="${MUCHO_DB_USER:-muchocore_user}"

log()  { printf '\033[1;32m[MuchoCore]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warning]\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }
trap 'fail "Ошибка на строке $LINENO. Проверьте вывод выше."' ERR

[[ $EUID -eq 0 ]] || fail "Запустите installer от root: sudo bash install.sh"
command -v apt-get >/dev/null 2>&1 || fail "Поддерживаются Debian/Ubuntu-подобные системы."
command -v systemctl >/dev/null 2>&1 || fail "Нужен Linux с systemd."

if [[ -z "$DOMAIN" ]]; then
  read -r -p "Домен GDPS (например gdps.example.com): " DOMAIN
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "Некорректный домен: $DOMAIN"

read -r -p "Логин администратора [admin]: " input_admin
ADMIN_USER="${input_admin:-$ADMIN_USER}"

if [[ -z "${MUCHO_DB_PASSWORD:-}" ]]; then
  read -r -s -p "Пароль БД (Enter = сгенерировать): " MUCHO_DB_PASSWORD
  printf '\n'
  MUCHO_DB_PASSWORD="${MUCHO_DB_PASSWORD:-$(openssl rand -hex 24)}"
fi

if [[ -z "${MUCHO_DB_ROOT_PASSWORD:-}" ]]; then
  MUCHO_DB_ROOT_PASSWORD="$(openssl rand -hex 32)"
fi

if [[ -z "${MUCHO_ADMIN_PASSWORD:-}" ]]; then
  read -r -s -p "Пароль админ-панели: " MUCHO_ADMIN_PASSWORD
  printf '\n'
fi

[[ -n "$MUCHO_DB_PASSWORD" ]] || fail "Пароль БД не задан."
[[ -n "$MUCHO_DB_ROOT_PASSWORD" ]] || fail "Не удалось создать root-пароль БД."
[[ -n "$MUCHO_ADMIN_PASSWORD" ]] || fail "Пароль админ-панели не может быть пустым."

log "Устанавливаю системные зависимости..."
apt-get update -y
apt-get install -y ca-certificates curl git openssl

log "Проверяю Docker..."
if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker
docker compose version >/dev/null 2>&1 || fail "Не найден Docker Compose v2 (команда: docker compose)."

log "Устанавливаю MuchoCore в $INSTALL_DIR..."
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch --depth=1 origin main
  git -C "$INSTALL_DIR" reset --hard origin/main
else
  rm -rf "$INSTALL_DIR"
  git clone --depth=1 "$REPO_URL" "$INSTALL_DIR"
fi

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] || fail "В репозитории нет docker-compose.yml."
[[ -f "$INSTALL_DIR/docker/Dockerfile" ]] || fail "В репозитории нет docker/Dockerfile."
[[ -f "$INSTALL_DIR/docker/Caddyfile" ]] || fail "В репозитории нет docker/Caddyfile."

install -d -m 700 "$INSTALL_DIR/.secrets"
printf '%s' "$MUCHO_DB_PASSWORD" > "$INSTALL_DIR/.secrets/db_password"
printf '%s' "$MUCHO_DB_ROOT_PASSWORD" > "$INSTALL_DIR/.secrets/db_root_password"
printf '%s' "$MUCHO_ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
chmod 600 "$INSTALL_DIR/.secrets/"*

cat > "$INSTALL_DIR/.env" <<EOFENV
DOMAIN=$DOMAIN
DB_NAME=$DB_NAME
DB_USER=$DB_USER
ADMIN_USER=$ADMIN_USER
MUCHO_ACCOUNT_URL=https://$DOMAIN
MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net
TZ=UTC
EOFENV
chmod 600 "$INSTALL_DIR/.env"

log "Запускаю MariaDB + PHP-FPM + Caddy..."
cd "$INSTALL_DIR"
docker compose up -d --build --remove-orphans

log "Проверяю health endpoint..."
healthy=0
for _ in {1..60}; do
  if curl -ksSf --max-time 3 "https://$DOMAIN/health" 2>/dev/null | grep -qx '1'; then
    healthy=1
    break
  fi
  sleep 2
done

if [[ "$healthy" -ne 1 ]]; then
  warn "Сервисы запущены, но https://$DOMAIN/health ещё не ответил 1."
  warn "Проверьте DNS домена и открытые TCP-порты 80/443."
fi

cat <<EOFOUT

MuchoCore установлен.

GDPS:   https://$DOMAIN
Admin:  https://$DOMAIN/admin/
Health: https://$DOMAIN/health
Каталог: $INSTALL_DIR

Обновление:
  sudo $INSTALL_DIR/update.sh

Логи:
  cd $INSTALL_DIR && sudo docker compose logs -f

Важно: не удаляйте volume db_data и не теряйте config/cloudsave.key.
EOFOUT
