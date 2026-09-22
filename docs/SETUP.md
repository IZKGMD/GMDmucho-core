# VPS: простой запуск

Эта инструкция рассчитана на новичка с Linux VPS и root-доступом.

## Что нужно

- Linux VPS;
- root-доступ или возможность использовать sudo;
- домен, который указывает на VPS;
- открытые порты 80 и 443.

Не нужно вручную устанавливать PHP, MariaDB или Caddy.

## 1. Настрой домен

Создай DNS-запись:

~~~text
gdps.example.com → IP-АДРЕС-ТВОЕГО-VPS
~~~

Подставь вместо gdps.example.com свой домен.

## 2. Запусти установщик

На VPS выполни:

~~~bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
~~~

Установщик задаст понятные вопросы и сам подготовит MuchoCore.

Он создаёт:

- MariaDB;
- PHP 8.3;
- Caddy;
- базу данных;
- ключ cloud save;
- администратора.

## 3. Проверь сервер

После установки открой:

~~~text
https://YOUR-DOMAIN/health
~~~

Должно появиться:

~~~text
1
~~~

Потом открой:

~~~text
https://YOUR-DOMAIN/admin/
~~~

Логин:

~~~text
admin
~~~

Пароль — тот, который ты задал установщику.

## 4. Подключи игру

Когда /health работает, переходи в:

CLIENT_SETUP.md

## Обновление

Обычно достаточно:

~~~bash
sudo /opt/mucho-core/update.sh
~~~

## Логи

Когда что-то не работает:

~~~bash
cd /opt/mucho-core
sudo docker compose logs --tail=100
~~~

Сначала посмотри последние строки. Не меняй сразу много файлов.

## Резервная копия

Перед большим изменением сделай backup:

~~~bash
sudo /opt/mucho-core/bin/mucho-db-backup.sh
~~~

И обязательно сохрани:

~~~text
/opt/mucho-core/config/cloudsave.key
~~~

Этот ключ нужен для существующих cloud save.

## Удаление

uninstall.sh удаляет установку и Docker volume с базой.

Перед запуском он просит ввести:

~~~text
DELETE
~~~

Не выполняй эту команду, если хочешь сохранить сервер.

## Если VPS необычный

NAT/CGNAT VPS, Cloudflare Tunnel и ручная настройка рассматриваются отдельно:

ADVANCED.md
