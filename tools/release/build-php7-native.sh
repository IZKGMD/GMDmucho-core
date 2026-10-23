#!/usr/bin/env bash
# Build a PHP-7.0-native GDPS subset, never mislabel the modern PHP8 core.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUTPUT="$ROOT/MuchoCore-PHP7-5v-native-preview.zip"
if [[ -n "${OUTPUT_PATH:-}" ]]; then
  OUTPUT="$OUTPUT_PATH"
fi
if [[ "$OUTPUT" != /* ]]; then
  OUTPUT="$ROOT/$OUTPUT"
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
for path in compat/php7/runtime.php compat/php7/download.php \
  compat/php7/upload.php compat/php7/auth.php compat/php7/news.php \
  compat/php7/schema.php compat/php7/users.php compat/php7/config.example.php \
  compat/php7/features.php compat/php7/features.sql \
  compat/php7/interactions.php compat/php7/cloudsave.php compat/php7/profile.php \
  compat/php7/admin.php compat/php7/admin-view.php compat/php7/bootstrap.php \
  compat/php7/social.php compat/php7/levels.php public/php7-admin.php \
  public/assets/php7-admin-base.css public/assets/php7-admin-host.js public/assets/muchocore-icon.png \
  public/php7.php public/php7-setup.php docs/PHP7_NATIVE_PHASE1.md; do
  if [[ ! -f "$ROOT/$path" ]]; then
    echo "Missing PHP7-native source: $path" >&2
    exit 1
  fi
done

mkdir -p "$STAGE/mc/compat" "$STAGE/mc/public" \
  "$STAGE/mc/config" "$STAGE/mc/docs"
cp -a "$ROOT/compat/php7" "$STAGE/mc/compat/"
cp "$ROOT/public/php7.php" "$ROOT/public/php7-setup.php" "$STAGE/mc/public/"
cp "$ROOT/public/php7-admin.php" "$STAGE/mc/public/"
mkdir -p "$STAGE/mc/public/assets"
cp "$ROOT/public/assets/muchocore-icon.png" "$ROOT/public/assets/muchocore-favicon-v4.png" "$STAGE/mc/public/assets/"
# Original VPS admin CSS, scripts and GD assets are copied byte-for-byte.
mkdir -p "$STAGE/mc/public/admin/assets"
for asset in admin-ui-v2.css admin-i18n.css admin-i18n.js admin-simple.css admin-simple.js admin-polish.css product-admin.css; do
  cp "$ROOT/public/admin/$asset" "$STAGE/mc/public/admin/"
done
cp -a "$ROOT/public/admin/assets/gd" "$STAGE/mc/public/admin/assets/"
cp "$ROOT/public/admin/assets/qrcode.min.js" "$STAGE/mc/public/admin/assets/"
cp "$ROOT/public/muchocore-theme.css" "$ROOT/public/muchocore-theme.js" "$STAGE/mc/public/"
for asset in product-ui.css php7-admin-base.css php7-admin-login.css php7-admin-product.js php7-admin-dashboard.js php7-admin-host.js; do
  cp "$ROOT/public/assets/$asset" "$STAGE/mc/public/assets/"
done
printf '%s\n' '<?php require __DIR__ . "/public/php7-admin.php";' > "$STAGE/mc/admin.php"
cp "$ROOT/docs/PHP7_NATIVE_PHASE1.md" "$STAGE/mc/docs/"
cp "$ROOT/patchers/shared-hosting/README.md" \
   "$STAGE/mc/docs/5V_APACHE_ROUTES.md"

# Port-specific root guard: no PHP8 classes, Composer, or unrelated routes.
cat > "$STAGE/mc/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex admin.php
RewriteEngine On
RewriteRule ^(?:config|compat|docs)(?:/|$) - [F,L,NC]
RewriteRule ^public/php7-mode\.on$ - [F,L,NC]
RewriteRule ^(?:\.env|\.git)(?:/|$) - [F,L,NC]
RewriteRule ^admin/?$ public/php7-admin.php [QSA,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ public/php7.php [QSA,L]
APACHE

cat > "$STAGE/mc/public/.htaccess" <<'APACHE'
Options -Indexes
DirectoryIndex php7-admin.php
RewriteEngine On
RewriteRule ^php7-mode\.on$ - [F,L,NC]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ php7.php [QSA,L]
APACHE

cat > "$STAGE/mc/config/.htaccess" <<'APACHE'
Options -Indexes
Require all denied
APACHE
cat > "$STAGE/mc/compat/.htaccess" <<'APACHE'
Options -Indexes
Require all denied
APACHE

cat > "$STAGE/5V_ROOT_REWRITE_RULES.txt" <<'APACHE'
# Add BELOW an existing RewriteEngine On and BEFORE catch-all rules
# in the main public WEBSITE ROOT .htaccess. Do not overwrite other rules.
RewriteRule ^(?:a|database|accounts|api)/(?:accounts/)?[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:getAccountURL|getCustomContentURL|checkIfServerOnline)\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^gd-content/news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news_version\.txt$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/news\.dat$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^(?:gd-content/)?news/mcnews_[a-f0-9]{64}\.jpg$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^news/image\.php$ mc/public/php7.php [QSA,L,NC]
APACHE

cat > "$STAGE/НАЧНИ_ЗДЕСЬ_5V.txt" <<'README'
MUCHOCORE SHARED — PHP 7.0+ / 5v.pl
===================================

Весь код этого архива работает на самом shared-хостинге: без VPS и Composer.
Это развивающийся native-порт. Полный список возможностей и ограничений:
mc/docs/PHP7_NATIVE_PHASE1.md. Не заменяй этим архивом PHP8-установку на VPS.

НОВАЯ УСТАНОВКА — БЕЗ РУЧНЫХ ТОКЕНОВ
-----------------------------------
1. Загрузи папку mc/ на хостинг через FileZilla.
2. Скопируй mc/compat/php7/config.example.php в mc/config/php7-local.php.
   Укажи host, port, database, username, password своей ПУСТОЙ MySQL-базы.
   Не публикуй этот файл и не отправляй его другим людям.
3. Открой https://ТВОЙ-ДОМЕН/mc/admin.php.
   Подтверди пароль базы из конфига. Задай логин, email и пароль владельца,
   а также HTTPS-домен сервера без /mc. По выбранному флажку панель включит
   регистрацию, запись, сохранения и социальные функции.
   База и секреты создадутся автоматически. Первый игрок не становится админом.
4. В .htaccess КОРНЯ ДОМЕНА добавь строки из 5V_ROOT_REWRITE_RULES.txt
   сразу после RewriteEngine On, перед общими правилами. Существующий файл
   не заменяй целиком. Это нужно для адресов /a/ и /accounts/ в клиенте.
5. Открой «Диагностика» в панели, затем проверь реальный клиент GD 2.209:
   регистрация/вход, загрузка и скачивание уровня, комментарии, друзья и save/sync.

ОБНОВЛЕНИЕ УЖЕ УСТАНОВЛЕННОГО NATIVE PHP7
---------------------------------------
1. Сделай резервную копию текущего /mc и базы.
2. Загрузи файлы из архива поверх /mc. СОХРАНИ свой mc/config/php7-local.php,
   mc/config/php7-news.json и public/php7-mode.on. В архиве их нет.
3. Открой /mc/admin.php. Если владельца ещё нет, подтверди пароль БД
   и укажи свой существующий игровой аккаунт с его текущим паролем.
4. Если панель предлагает «Обновить базу», нажми эту кнопку.
   Обновление только добавляет таблицы. Аккаунты, уровни и сохранения
   не очищаются. Повторный запуск php7-setup.php не нужен.
   Альтернатива: импортировать mc/compat/php7/features.sql через phpMyAdmin.
5. В «Настройках» проверь домен и переключатели функций.

ЧТО ЕСТЬ В ЭТОЙ СБОРКЕ
---------------------
* Русская мобильная админка: игроки, роли/баны, оценка и восстановление уровней,
  комментарии, песни, новости, настройки, диагностика, журнал и SQL-бэкап.
* Аккаунты, публичные профили/поиск/топ, статистика и внешний вид персонажа.
* Уровни до 8 МиБ с сохранением metadata, songIDs, sfxIDs и editor extras.
* Комментарии уровня, посты профиля, история, лайки и дизлайки.
* Друзья, заявки, блокировки, ЛС и настройки приватности.
* Сохранения до 8 МиБ в локальной БД с проверкой целостности.
* Исходный News Board: черновики, закрепление, изображения и YouTube для GD 2.209.
* Исходные карточки 2FA: локальный QR, одноразовые резервные коды.
* Без проксирования игровых запросов на внешний сервер.

ЛИМИТЫ ХОСТИНГА
---------------
Для 8 МиБ уровней/сохранений: post_max_size не меньше 26M,
memory_limit не меньше 128M. Ограничения HTTP-сервера/провайдера тоже действуют.
PHP 7.0 больше не получает исправления безопасности; тесты кода не заменяют
проверку настроек конкретного хостинга и реального клиента.

Пока не перенесены: кланы, ежедневные награды/квесты, пакеты/списки уровней,
новые 2.209 threads/following, полноценный мигратор чужих ядер, клиентский
патчер и вся система PHP8-плагинов. Не выдавай их за поддерживаемые функции.
Этот архив не импортирует старые PHP8/Cvolton comments, ЛС и saves автоматически.

README

# Prohibit secrets in distributed ZIP; installer-generated keys/configs
# are never copied into this stage.
mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
(cd "$STAGE" && zip -qr "$OUTPUT" mc \
 "5V_ROOT_REWRITE_RULES.txt" "НАЧНИ_ЗДЕСЬ_5V.txt")

unzip -t "$OUTPUT" >/dev/null
LIST="$STAGE/zip-list.txt"
unzip -Z1 "$OUTPUT" > "$LIST"
for item in mc/admin.php mc/public/php7-admin.php mc/public/assets/php7-admin-base.css mc/public/assets/php7-admin-host.js \
  mc/public/php7.php mc/public/php7-setup.php \
  mc/compat/php7/users.php mc/compat/php7/runtime.php \
  mc/compat/php7/news.php mc/compat/php7/schema.php \
  mc/compat/php7/features.sql mc/compat/php7/interactions.php \
  mc/compat/php7/cloudsave.php mc/compat/php7/profile.php \
  mc/.htaccess mc/public/.htaccess \
  5V_ROOT_REWRITE_RULES.txt; do
  grep -Fxq "$item" "$LIST" || {
    echo "Package incomplete: $item" >&2
    exit 1
  }
done
for forbidden in mc/config/php7-local.php mc/config/php7-news.json \
  mc/config/php7-setup-key.txt mc/public/php7-mode.on \
  mc/vendor/autoload.php mc/src/Core/Application.php; do
  if grep -Fxq "$forbidden" "$LIST"; then
    echo "Forbidden private or PHP8-only package file: $forbidden" >&2
    exit 1
  fi
done
echo "PHP7_NATIVE_5V_ZIP_OK"
echo "OUTPUT=$OUTPUT"
