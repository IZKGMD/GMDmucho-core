# MuchoCore — простая структура

Главное правило:

> **Один тип задачи = одна папка.**

## Что рекомендуется

**Для настоящего публичного GDPS рекомендуется VPS.**

Shared hosting тоже поддерживается, но VPS даёт полный контроль над сервером, Docker, базой данных и обновлениями.

## Что где лежит

```text
GMDmucho-core/
│
├─ src/                 ← ВЕСЬ основной PHP-код сервера
│  ├─ Account/          ← регистрация и вход
│  ├─ User/             ← профиль и статистика игрока
│  ├─ Level/            ← уровни
│  ├─ Score/            ← рекорды
│  ├─ Social/           ← друзья и сообщения
│  ├─ Interaction/      ← лайки, комментарии, награды
│  ├─ CloudSave/        ← сохранения аккаунта
│  ├─ Music/            ← музыка
│  ├─ Moderation/       ← модерация
│  ├─ Protocol/         ← формат ответов Geometry Dash
│  ├─ Routing/          ← куда отправлять запрос
│  ├─ Database/         ← подключение к БД и миграции
│  └─ V71/              ← старый совместимый слой GD
│
├─ public/              ← ТО, что доступно через браузер
│  ├─ index.php         ← главный вход сервера
│  ├─ admin/            ← админ-панель
│  └─ api/               ← веб-API
│
├─ database/
│  └─ migrations/       ← создание и изменение таблиц БД
│
├─ docker/              ← Docker/PHP/Caddy
│
├─ tests/               ← автоматические проверки
│
├─ tools/               ← утилиты для клиента и диагностики
│
├─ bin/                 ← команды сервера
│
├─ docs/                ← документация
│
├─ install.sh           ← первая установка
├─ update.sh            ← обновление
└─ uninstall.sh         ← удаление
```

## Как работает запрос

Очень просто:

```text
Geometry Dash
     ↓
public/index.php
     ↓
Routing
     ↓
нужный Controller
     ↓
Service
     ↓
Repository
     ↓
MySQL/MariaDB
```

Например, игрок загружает уровень:

```text
GD
 ↓
public/index.php
 ↓
LevelTransferController
 ↓
LevelTransferService
 ↓
LevelTransferRepository
 ↓
База
```

## Куда лезть, если нужно что-то изменить

| Что надо изменить | Куда смотреть |
|---|---|
| Регистрация / вход | `src/Account/` |
| Профиль игрока | `src/User/` |
| Загрузка уровня | `src/Level/LevelTransfer*` |
| Поиск уровней | `src/Level/` |
| Рекорды | `src/Score/` |
| Друзья | `src/Social/Relationship*` |
| Сообщения | `src/Social/Message*` |
| Комментарии | `src/Interaction/Comment*` |
| Лайки | `src/Interaction/Like*` |
| Cloud Save | `src/CloudSave/` |
| Музыка | `src/Music/` |
| Пароль / GJP | `src/Account/` |
| Формат ответа GD | `src/Protocol/` |
| Маршруты | `src/Routing/` и `src/Core/Application.php` |
| Таблицы БД | `database/migrations/` |
| Админка | `public/admin/` |
| Тесты | `tests/` |
| Docker | `docker/` + `docker-compose.yml` |

## Что не нужно трогать без причины

- `src/V71/` — это слой совместимости.
- `vendor/` — создаётся Composer.
- `.env` — секреты и настройки.
- `config/cloudsave.key` — ключ старых Cloud Save.
- `database/migrations/` — старые миграции не переписываем; добавляем новую.

## Главное правило для будущих изменений

Не искать проблему во всём проекте.

Сначала определить **что сломалось**, потом идти в соответствующую папку.

Например:

- не входит аккаунт → `Account`
- не скачивается уровень → `Level`
- не работают друзья → `Social`
- не сохраняется профиль → `User`
- сервер не видит таблицу → `database/migrations`
- GD получает неправильную строку → `Protocol`
