#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO_URL="${MUCHO_REPO_URL:-https://github.com/IZKGMD/GMDmucho-core.git}"
INSTALL_DIR="${MUCHO_INSTALL_DIR:-/opt/mucho-core}"
DOMAIN="${MUCHO_DOMAIN:-}"
DB_NAME="${MUCHO_DB_NAME:-muchocore}"
DB_USER="${MUCHO_DB_USER:-muchocore_user}"
ADMIN_USER="admin"
ADMIN_PASSWORD="${MUCHO_ADMIN_PASSWORD:-}"
SHOW_PASSWORD="${MUCHO_SHOW_ADMIN_PASSWORD:-0}"

GREEN='\033[1;32m'
YELLOW='\033[1;33m'
RED='\033[1;31m'
RESET='\033[0m'

log() {
    printf '%b[MuchoCore]%b %s\n' "$GREEN" "$RESET" "$*"
}

warn() {
    printf '%b[warning]%b %s\n' "$YELLOW" "$RESET" "$*" >&2
}

fail() {
    printf '%b[error]%b %s\n' "$RED" "$RESET" "$*" >&2
    exit 1
}

on_error() {
    fail "Установка остановлена на строке $1. Смотри сообщение выше."
}

trap 'on_error $LINENO' ERR

usage() {
    cat <<'EOF'

MuchoCore — установка на VPS

Использование:
  sudo bash install.sh
  sudo bash install.sh --domain gdps.example.com

Параметры:
  --domain DOMAIN
  --install-dir PATH
  --db-name NAME
  --db-user NAME
  --admin-password PASSWORD

Можно также использовать переменные:
  MUCHO_DOMAIN
  MUCHO_INSTALL_DIR
  MUCHO_DB_NAME
  MUCHO_DB_USER
  MUCHO_ADMIN_PASSWORD

EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)
            [[ $# -ge 2 ]] || fail "После --domain нужен домен."
            DOMAIN="$2"
            shift 2
            ;;
        --install-dir)
            [[ $# -ge 2 ]] || fail "После --install-dir нужен путь."
            INSTALL_DIR="$2"
            shift 2
            ;;
        --db-name)
            [[ $# -ge 2 ]] || fail "После --db-name нужно имя базы."
            DB_NAME="$2"
            shift 2
            ;;
        --db-user)
            [[ $# -ge 2 ]] || fail "После --db-user нужно имя пользователя БД."
            DB_USER="$2"
            shift 2
            ;;
        --admin-password)
            [[ $# -ge 2 ]] || fail "После --admin-password нужен пароль."
            ADMIN_PASSWORD="$2"
            shift 2
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "Неизвестный параметр: $1. Используй --help."
            ;;
    esac
done

[[ $EUID -eq 0 ]] || fail "Запусти установщик через sudo."

command -v apt-get >/dev/null 2>&1 ||     fail "Нужна Debian/Ubuntu-подобная VPS с apt."

command -v systemctl >/dev/null 2>&1 ||     fail "Нужен systemd."

if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    case "${ID:-}" in
        ubuntu|debian)
            ;;
        *)
            warn "ОС не Ubuntu/Debian (${ID:-unknown}). Установка продолжится."
            ;;
    esac
fi

validate_domain() {
    local value="$1"

    [[ ${#value} -le 253 ]] || return 1
    [[ "$value" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || return 1
    [[ "$value" != *..* ]] || return 1
    [[ "$value" != *"/"* ]] || return 1
    [[ "$value" != *":"* ]] || return 1

    return 0
}

if [[ -z "$DOMAIN" && -f "$INSTALL_DIR/.env" ]]; then
    DOMAIN="$(sed -n 's/^DOMAIN=//p' "$INSTALL_DIR/.env" | head -n1 || true)"
fi

if [[ -z "$DOMAIN" ]]; then
    printf 'Домен GDPS (например gdps.example.com): '
    read -r DOMAIN
fi

validate_domain "$DOMAIN" || fail "Некорректный домен: $DOMAIN"

[[ "$INSTALL_DIR" == /* ]] || fail "INSTALL_DIR должен быть абсолютным путём."

if [[ "$INSTALL_DIR" == "/" || "$INSTALL_DIR" == "/opt" ]]; then
    fail "Опасный путь установки: $INSTALL_DIR"
fi

log "Проверяю VPS..."

log "Устанавливаю базовые зависимости..."
apt-get update -y
apt-get install -y ca-certificates curl git openssl iproute2

log "Проверяю Docker..."
if ! command -v docker >/dev/null 2>&1; then
    log "Docker не найден. Устанавливаю Docker..."
    curl -fsSL --retry 5 --retry-delay 2 https://get.docker.com | sh
fi

systemctl enable --now docker

docker info >/dev/null 2>&1 || fail "Docker daemon недоступен."
docker compose version >/dev/null 2>&1 || fail "Не найден Docker Compose v2."

port_in_use() {
    local port="$1"

    ss -ltnH 2>/dev/null |
        awk '{print $4}' |
        grep -Eq "(:|\])${port}$"
}

log "Проверяю порты 80/443..."

EXISTING_CADDY="$(docker ps --filter 'label=com.docker.compose.service=caddy' -q | head -n1 || true)"

if [[ -z "$EXISTING_CADDY" ]]; then
    port_in_use 80 && fail "Порт 80 уже занят."
    port_in_use 443 && fail "Порт 443 уже занят."
fi

log "Проверяю DNS..."
if ! getent ahostsv4 "$DOMAIN" >/dev/null 2>&1; then
    warn "Домен пока не резолвится. HTTPS снаружи заработает после настройки DNS."
fi

log "Готовлю MuchoCore..."

if [[ -e "$INSTALL_DIR" && ! -d "$INSTALL_DIR" ]]; then
    fail "Путь $INSTALL_DIR уже занят файлом."
fi

if [[ -d "$INSTALL_DIR" ]]; then
    if [[ -d "$INSTALL_DIR/.git" ]]; then
        log "Найдена существующая установка. Исходники не стираю."
    elif [[ -z "$(find "$INSTALL_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
        log "Каталог пустой — использую его."
        git clone --depth=1 "$REPO_URL" "$INSTALL_DIR"
    else
        fail "Каталог $INSTALL_DIR существует и не похож на MuchoCore. Ничего не удаляю."
    fi
else
    git clone --depth=1 "$REPO_URL" "$INSTALL_DIR"
fi

[[ -f "$INSTALL_DIR/docker-compose.yml" ]] || fail "В репозитории нет docker-compose.yml."
[[ -f "$INSTALL_DIR/docker/Dockerfile" ]] || fail "В репозитории нет docker/Dockerfile."
[[ -f "$INSTALL_DIR/docker/Caddyfile" ]] || fail "В репозитории нет docker/Caddyfile."

install -d -m 700 "$INSTALL_DIR/.secrets"

NEW_ADMIN_PASSWORD=0

if [[ -s "$INSTALL_DIR/.secrets/admin_password" ]]; then
    ADMIN_PASSWORD="$(cat "$INSTALL_DIR/.secrets/admin_password")"
elif [[ -n "$ADMIN_PASSWORD" ]]; then
    [[ ${#ADMIN_PASSWORD} -ge 12 ]] ||         fail "Пароль администратора должен содержать минимум 12 символов."

    printf '%s' "$ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
else
    ADMIN_PASSWORD="$(openssl rand -hex 18)"
    printf '%s' "$ADMIN_PASSWORD" > "$INSTALL_DIR/.secrets/admin_password"
    NEW_ADMIN_PASSWORD=1
fi

if [[ -s "$INSTALL_DIR/.secrets/db_password" ]]; then
    DB_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_password")"
else
    DB_PASSWORD="$(openssl rand -hex 24)"
    printf '%s' "$DB_PASSWORD" > "$INSTALL_DIR/.secrets/db_password"
fi

if [[ -s "$INSTALL_DIR/.secrets/db_root_password" ]]; then
    DB_ROOT_PASSWORD="$(cat "$INSTALL_DIR/.secrets/db_root_password")"
else
    DB_ROOT_PASSWORD="$(openssl rand -hex 32)"
    printf '%s' "$DB_ROOT_PASSWORD" > "$INSTALL_DIR/.secrets/db_root_password"
fi

chmod 600 "$INSTALL_DIR/.secrets/"*

cat > "$INSTALL_DIR/.env.tmp" <<EOFENV
DOMAIN=$DOMAIN
DB_NAME=$DB_NAME
DB_USER=$DB_USER
ADMIN_USER=$ADMIN_USER
MUCHO_ACCOUNT_URL=https://$DOMAIN
MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net
MUCHO_ADMIN_BOOTSTRAP=/etc/muchocore-admin.php
MUCHO_CONTROL_DIR=/var/lib/muchocore-control
MUCHO_BACKUP_DIR=/var/lib/muchocore-backups
TZ=UTC
EOFENV

chmod 600 "$INSTALL_DIR/.env.tmp"
mv "$INSTALL_DIR/.env.tmp" "$INSTALL_DIR/.env"

log "Проверяю Docker Compose..."
cd "$INSTALL_DIR"
docker compose config --quiet

log "Запускаю MuchoCore..."
docker compose up -d --build --remove-orphans

log "Жду готовности сервера..."
healthy=0

for _ in {1..60}; do
    if curl -4ksSf         --connect-timeout 2         --max-time 4         --resolve "$DOMAIN:443:127.0.0.1"         "https://$DOMAIN/health" 2>/dev/null |
        grep -qx "1"; then
        healthy=1
        break
    fi

    sleep 2
done

if [[ "$healthy" -ne 1 ]]; then
    docker compose ps || true

    echo
    echo "===== Последние логи ====="
    docker compose logs --tail=80 || true

    fail "MuchoCore не прошёл локальную проверку /health."
fi

log "Локальная проверка /health прошла."

public_ok=0

for _ in {1..10}; do
    if curl -4ksSf         --connect-timeout 3         --max-time 5         "https://$DOMAIN/health" 2>/dev/null |
        grep -qx "1"; then
        public_ok=1
        break
    fi

    sleep 3
done

echo
echo "========================================"
echo " MuchoCore установлен"
echo "========================================"
echo
printf 'GDPS:       https://%s\n' "$DOMAIN"
printf 'Админка:    https://%s/admin/\n' "$DOMAIN"
printf 'Health:     https://%s/health\n' "$DOMAIN"
printf 'Путь:       %s\n' "$INSTALL_DIR"
printf 'Админ:      %s\n' "$ADMIN_USER"

if [[ "$NEW_ADMIN_PASSWORD" -eq 1 ]]; then
    echo
    echo "========================================"
    echo " Сохрани пароль администратора"
    echo "========================================"
    printf '%s\n' "$ADMIN_PASSWORD"
    echo
else
    echo "Пароль:     уже сохранён в .secrets/admin_password"
    if [[ "$SHOW_PASSWORD" == "1" ]]; then
        echo
        echo "Текущий пароль:"
        printf '%s\n' "$ADMIN_PASSWORD"
    fi
fi

if [[ "$public_ok" -eq 1 ]]; then
    log "Публичная проверка /health прошла."
else
    warn "Сервер работает, но домен пока не доступен снаружи."
    warn "Проверь DNS и порты 80/443."
fi

echo
echo "Обновление:"
echo "  sudo $INSTALL_DIR/update.sh"
echo
echo "Логи:"
echo "  cd $INSTALL_DIR && sudo docker compose logs -f"
echo
