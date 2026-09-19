#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="/var/www/mucho-core"
BACKUP_DIR="$ROOT/backups/database"
LOG_DIR="$ROOT/logs"
LOG="$LOG_DIR/db-backup.log"
LOCK="/run/mucho-db-backup.lock"

RETENTION_MINUTES=20160   # 14 суток

mkdir -p "$BACKUP_DIR" "$LOG_DIR"

# Не допускаем одновременного запуска двух backup
exec 9>"$LOCK"

if ! flock -n 9; then
    echo "[$(date -Is)] Backup already running, skip." >> "$LOG"
    exit 0
fi


# ------------------------------------------------------------
# Проверяем dump utility
# ------------------------------------------------------------

if command -v mariadb-dump >/dev/null 2>&1; then
    DUMP_BIN="$(command -v mariadb-dump)"
elif command -v mysqldump >/dev/null 2>&1; then
    DUMP_BIN="$(command -v mysqldump)"
else
    echo "[$(date -Is)] ERROR: mysqldump/mariadb-dump not found" >> "$LOG"
    exit 1
fi


# ------------------------------------------------------------
# Получаем DB credentials из MuchoCore .env
# ------------------------------------------------------------

TMP_CNF="$(mktemp)"
chmod 600 "$TMP_CNF"

cleanup() {
    rm -f "$TMP_CNF"
}

trap cleanup EXIT


DB_NAME="$(
php /dev/stdin "$TMP_CNF" <<'PHP'
<?php

$root = '/var/www/mucho-core';
$cnf  = $argv[1];

$envFile = $root . '/.env';

if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found\n");
    exit(1);
}

$lines = file(
    $envFile,
    FILE_IGNORE_NEW_LINES |
    FILE_SKIP_EMPTY_LINES
);

$env = [];

foreach ($lines as $line) {

    $line = trim($line);

    if (
        $line === '' ||
        str_starts_with($line, '#')
    ) {
        continue;
    }

    if (str_starts_with($line, 'export ')) {
        $line = substr($line, 7);
    }

    $pos = strpos($line, '=');

    if ($pos === false) {
        continue;
    }

    $key = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));

    if (
        strlen($value) >= 2 &&
        (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )
    ) {
        $value = substr($value, 1, -1);
    }

    $env[$key] = $value;
}


$host = '127.0.0.1';
$port = '3306';
$user = '';
$pass = '';
$db   = '';


if (!empty($env['DATABASE_URL'])) {

    $url = parse_url($env['DATABASE_URL']);

    if ($url === false) {
        fwrite(STDERR, "ERROR: Invalid DATABASE_URL\n");
        exit(1);
    }

    $host = $url['host'] ?? $host;
    $port = (string)($url['port'] ?? $port);

    $user = isset($url['user'])
        ? urldecode($url['user'])
        : '';

    $pass = isset($url['pass'])
        ? urldecode($url['pass'])
        : '';

    $db = isset($url['path'])
        ? ltrim(
            urldecode($url['path']),
            '/'
        )
        : '';

} else {

    $host =
        $env['DB_HOST']
        ?? $host;

    $port =
        $env['DB_PORT']
        ?? $port;

    $user =
        $env['DB_USER']
        ?? $env['DB_USERNAME']
        ?? '';

    $pass =
        $env['DB_PASS']
        ?? $env['DB_PASSWORD']
        ?? '';

    $db =
        $env['DB_NAME']
        ?? $env['DB_DATABASE']
        ?? '';
}


if ($user === '' || $db === '') {
    fwrite(
        STDERR,
        "ERROR: Database credentials incomplete\n"
    );
    exit(1);
}


function cnfQuote(string $value): string
{
    return '"' .
        str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value
        ) .
        '"';
}


$config =
    "[client]\n" .
    "host="     . cnfQuote($host) . "\n" .
    "port="     . cnfQuote($port) . "\n" .
    "user="     . cnfQuote($user) . "\n" .
    "password=" . cnfQuote($pass) . "\n";


if (
    file_put_contents(
        $cnf,
        $config
    ) === false
) {
    fwrite(
        STDERR,
        "ERROR: Cannot create temporary DB config\n"
    );
    exit(1);
}

chmod($cnf, 0600);

echo $db;
PHP
)"


if [ -z "$DB_NAME" ]; then
    echo "[$(date -Is)] ERROR: Empty DB name" >> "$LOG"
    exit 1
fi


SAFE_DB="$(
    printf '%s' "$DB_NAME" |
    tr -cd 'A-Za-z0-9_.-'
)"

if [ -z "$SAFE_DB" ]; then
    SAFE_DB="muchocore"
fi


# ------------------------------------------------------------
# Имена файлов
# ------------------------------------------------------------

STAMP="$(date -u +%Y%m%d_%H%M%S)"

FINAL="$BACKUP_DIR/${SAFE_DB}_${STAMP}.sql.gz"
TMP="$FINAL.tmp"


# ------------------------------------------------------------
# Backup
# ------------------------------------------------------------

{
    echo
    echo "========================================"
    echo "START $(date -Is)"
    echo "DATABASE=$DB_NAME"
    echo "FILE=$FINAL"
    echo "========================================"

} >> "$LOG"


if ! "$DUMP_BIN" \
    --defaults-extra-file="$TMP_CNF" \
    --single-transaction \
    --quick \
    --triggers \
    --hex-blob \
    --default-character-set=utf8mb4 \
    "$DB_NAME" \
    | gzip -9 > "$TMP"
then
    rm -f "$TMP"

    echo "[$(date -Is)] ERROR: database dump failed" >> "$LOG"
    exit 1
fi


# ------------------------------------------------------------
# Проверяем gzip
# ------------------------------------------------------------

if ! gzip -t "$TMP"; then
    rm -f "$TMP"

    echo "[$(date -Is)] ERROR: gzip verification failed" >> "$LOG"
    exit 1
fi


# ------------------------------------------------------------
# Проверяем, что файл не пустой
# ------------------------------------------------------------

SIZE="$(stat -c '%s' "$TMP")"

if [ "$SIZE" -lt 100 ]; then
    rm -f "$TMP"

    echo "[$(date -Is)] ERROR: backup suspiciously small" >> "$LOG"
    exit 1
fi


# ------------------------------------------------------------
# Публикуем backup
# ------------------------------------------------------------

mv "$TMP" "$FINAL"

chown root:www-data "$FINAL"
chmod 640 "$FINAL"


# SHA-256
sha256sum "$FINAL" > "$FINAL.sha256"

chown root:www-data "$FINAL.sha256"
chmod 640 "$FINAL.sha256"


# ------------------------------------------------------------
# Удаляем backup старше 14 суток
# ------------------------------------------------------------

DELETED="$(
    find "$BACKUP_DIR" \
        -type f \
        -mmin +"$RETENTION_MINUTES" \
        \( \
            -name '*.sql.gz' \
            -o \
            -name '*.sql.gz.sha256' \
        \) \
        -print \
        -delete \
        | wc -l
)"


# ------------------------------------------------------------
# Финальный лог
# ------------------------------------------------------------

HUMAN_SIZE="$(
    du -h "$FINAL" |
    awk '{print $1}'
)"

HASH="$(
    awk '{print $1}' "$FINAL.sha256"
)"

{
    echo "BACKUP_OK"
    echo "SIZE=$HUMAN_SIZE"
    echo "SHA256=$HASH"
    echo "OLD_FILES_DELETED=$DELETED"
    echo "FINISH $(date -Is)"
    echo "========================================"

} >> "$LOG"


echo "BACKUP_OK"
echo "FILE=$FINAL"
echo "SIZE=$HUMAN_SIZE"
echo "SHA256=$HASH"
echo "OLD_FILES_DELETED=$DELETED"
