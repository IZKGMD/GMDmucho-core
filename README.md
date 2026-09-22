# MuchoCore

MuchoCore — backend для GDPS на Geometry Dash.

**Готовящаяся версия: v1.0.1**

> **Новичок? Начни с [START_HERE.md](START_HERE.md).**
>
> Не нужно читать весь репозиторий, чтобы запустить сервер.

## Что внутри

- аккаунты и профили;
- уровни и списки уровней;
- оценки, комментарии и социальные функции;
- cloud save;
- админ-панель;
- совместимость с несколькими поколениями клиента;
- JSON API v2;
- автоматические тесты.

## Быстрый выбор

| У тебя есть | Открывай |
| --- | --- |
| Linux VPS с root-доступом | [docs/SETUP.md](docs/SETUP.md) |
| Обычный PHP-хостинг | [docs/SHARED_HOSTING.md](docs/SHARED_HOSTING.md) |
| Уже запустился сервер, нужен клиент | [docs/CLIENT_SETUP.md](docs/CLIENT_SETUP.md) |
| Нужно разобраться глубже | [docs/ADVANCED.md](docs/ADVANCED.md) |

## После установки

Проверь:

~~~text
https://YOUR-DOMAIN/health
~~~

Ожидаемый ответ:

~~~text
1
~~~

Админ-панель:

~~~text
https://YOUR-DOMAIN/admin/
~~~

Пользователь сервера по умолчанию:

~~~text
admin
~~~

Пароль задаётся во время установки.

## Подключение Geometry Dash

Для Windows есть:

~~~text
tools/client-patch.bat
~~~

Патчер создаёт отдельный файл клиента и не заменяет исходный EXE.

Важно: успешный запуск патчера означает только успешную замену известных URL-строк. Полная совместимость с конкретной сборкой Geometry Dash подтверждается только реальным тестом клиента.

## Структура

~~~text
START_HERE.md      ← сюда новичку
README.md          ← краткая карта проекта
src/               ← логика сервера
public/            ← HTTP-входы и GD endpoint'ы
database/          ← миграции
tests/             ← проверки
tools/             ← инструменты
docs/              ← подробные инструкции
docker/            ← Docker/Caddy
~~~

### Что обычно не нужно трогать

Новичку обычно не нужны:

~~~text
src/
database/
docker/
tests/
~~~

Сначала настрой сервер через инструкцию, затем проверяй /health.

## Безопасность

Никогда не публикуй:

~~~text
.env
config/cloudsave.key
storage/
.secrets/
~~~

Перед удалением установки прочитай предупреждение uninstall.sh: он удаляет контейнеры и базу.

## Проверки разработчика

Основные автоматические проверки:

~~~bash
php tests/client-compatibility.php
php tests/router-compatibility.php
python3 tools/client-patch.py --self-test
bash tests/client-contract.sh
~~~

CI дополнительно проверяет shell/PHP/Python-код, Docker/Caddy-конфигурацию, shared-hosting routing и Windows PowerShell patcher.

Реальный Geometry Dash клиент всё равно нужно тестировать отдельно.

## Лицензия

MIT. См. [LICENSE](LICENSE).
