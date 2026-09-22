# Продвинутые сценарии

Эта страница не нужна для обычной установки.

## NAT / CGNAT VPS

Если VPS не принимает входящие подключения на 80 и 443, обычная HTTPS-схема не заработает напрямую.

Один из вариантов — Cloudflare Tunnel. Он создаёт исходящее соединение с сервера к Cloudflare, поэтому отдельный входящий проброс 80/443 на сам VPS не требуется.

### Вариант для установщика MuchoCore

1. В Cloudflare Dashboard открой **Networking → Tunnels**.
2. Создай или выбери Tunnel типа cloudflared.
3. Создай Published application для своего домена.
4. Для origin укажи:

~~~text
http://caddy:80
~~~

5. Скопируй connector token.
6. На VPS передай его установщику через переменную MUCHO_TUNNEL_TOKEN:

~~~bash
export MUCHO_TUNNEL_TOKEN='YOUR_TUNNEL_TOKEN'
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo -E bash install.sh
~~~

В tunnel-режиме MuchoCore переводит Caddy на обычный HTTP внутри Docker и поднимает cloudflared отдельным контейнером. TLS завершается на стороне Cloudflare.

**Не публикуй connector token.** Это секрет подключения Tunnel.

### Что делать, если Tunnel уже создан

В существующем Tunnel открой его страницу и используй раздел подключения connector-а. В зависимости от версии Dashboard Cloudflare может показывать установку для Linux вместо старой кнопки Add a replica.

Для обычного публичного VPS Tunnel не нужен — используй docs/SETUP.md.

## Shared hosting без Docker

Shared hosting — отдельный режим. Он использует PHP 8.3+, MySQL/MariaDB и Apache или аналогичный веб-сервер.

Основная инструкция:

SHARED_HOSTING.md

## API v2

JSON API v2 предназначен для программ, админских инструментов и интеграций. Для обычного запуска GDPS его изучать не требуется.

Спецификация:

openapi.yaml

## Реальная совместимость клиента

Автотесты проверяют серверный код и известные маршруты. Они не заменяют запуск настоящей версии Geometry Dash.

Для проверки новой версии клиента используй:

CLIENT_TESTING.md

## Структура для разработчиков

~~~text
src/        основная логика
public/     HTTP-входы
            database/ — legacy GD endpoint'ы
            api/v2/   — JSON API
            admin/    — web-admin
database/   миграции
tests/      автоматические проверки
tools/      инструменты
docker/     контейнеры и Caddy
~~~

Новичку не нужно редактировать исходники для обычной установки.

## Главное правило

Сначала повторяй простую инструкцию из START_HERE.md. Продвинутые настройки меняй только тогда, когда понимаешь, какую проблему они решают.
