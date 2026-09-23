# MuchoCore — начать здесь

<img src="public/assets/muchocore-mark.svg" alt="MuchoCore" width="84">

Добро пожаловать! MuchoCore можно запускать даже без глубоких знаний PHP или Docker.

## Что это

MuchoCore — серверная часть GDPS для Geometry Dash.

Проще всего представить так:

```
Geometry Dash
     ↓
MuchoCore
     ↓
MySQL / MariaDB
```

## Я новичок

Открой только один из двух путей:

### 1. VPS

Это основной вариант для полноценного сервера.

Начни с:

```
docs/SETUP.md
```

Самый простой запуск:

```bash
curl -fsSL https://raw.githubusercontent.com/IZKGMD/GMDmucho-core/main/install.sh -o install.sh
sudo bash install.sh
```

Установщик сам создаёт контейнеры, базу, HTTPS и администратора.

### 2. Обычный PHP-хостинг

Подходит для простого или тестового сервера.

Начни с:

```
docs/SHARED_HOSTING.md
```

Нужны PHP 8.3+, MySQL/MariaDB и доступ к файлам сайта.

## Когда что-то не работает

Не ищи ошибку по всему проекту. Выполни:

```bash
bash bin/mucho doctor
```

После этого смотри только на строки `FAIL` и `WARN`.

Полезные команды:

```bash
bash bin/mucho status
bash bin/mucho logs
bash bin/mucho health
```

## После установки

Проверь:

```
https://YOUR-DOMAIN/health
```

Нормальный ответ:

```
1
```

Потом открой:

```
https://YOUR-DOMAIN/admin/
```

Логин администратора:

```
admin
```

Пароль — тот, который ты задал при установке.

## Подключить игру

После того как сервер отвечает на `/health`, переходи в:

```
docs/CLIENT_SETUP.md
```

Для Windows есть простой патчер:

```
tools/client-patch.bat
```

Он создаёт отдельный EXE и не изменяет оригинальный файл.

## Что означают папки

```
src/       — основная логика сервера
public/    — HTTP-точки входа и совместимые GD endpoint'ы
database/  — миграции базы данных
config/    — локальная конфигурация и ключи
storage/   — данные работы сервера и служебные файлы
tests/     — автоматические проверки
tools/     — полезные инструменты
docs/      — инструкции
docker/    — файлы Docker-развёртывания
```

Не нужно разбираться во всех папках сразу.

## Важно

Не публикуй:

```
.env
config/cloudsave.key
storage/
```

И не запускай `uninstall.sh`, пока не понимаешь, что он удаляет базу и установку.

## Нужна помощь?

Сначала проверь:

1. `/health`
2. последнюю строку ошибки в журнале
3. соответствующую инструкцию в `docs/`

Для новичка лучше менять одну вещь за раз и после каждого изменения снова проверять `/health`.
