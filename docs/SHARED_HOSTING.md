# MuchoCore на обычном PHP-хостинге

Это полноценный вариант MuchoCore для shared hosting: **без Docker, без VPS и без обязательного Terminal**.

## Что нужно

- PHP 8.3 или новее;
- MySQL 8.0.29+ или MariaDB 10.4+;
- PDO MySQL;
- HTTPS;
- FTP / файловый менеджер;
- база данных и пользователь базы.

### Важное отличие от VPS

На shared hosting нет Docker и нет systemd. Поэтому MuchoCore использует обычный PHP runtime, файловое хранилище и PHP-резервное копирование базы.

## 1. Скачай правильный пакет

Для обычного shared hosting используй файл:

```text
MuchoCore-vX.Y.Z-shared-hosting.zip
```

Этот пакет уже содержит `vendor/`. Composer и Terminal для установки не нужны.

Не используй для shared hosting VPS-архив как единственный пакет: в обычном исходном архиве `vendor/` отсутствует.

## 2. Распакуй через FTP

Распакуй содержимое архива в каталог сайта.

Структура должна выглядеть так:

```text
muchocore/
├── public/
├── src/
├── database/
├── config/
├── vendor/
├── composer.json
├── composer.lock
├── .htaccess
└── ...
```

Не переименовывай и не перемещай отдельно `public/`.

### Два варианта document root

**Вариант A — предпочтительный:** document root указывает прямо на:

```text
.../muchocore/public
```

**Вариант B:** если хостинг не позволяет изменить document root, оставь его на корне проекта. Корневой `.htaccess` сам направит публичные запросы в `public/`.

## 3. Создай пустую базу

В панели хостинга открой MySQL / MariaDB / Databases.

Нужны:

```text
DB host
DB port
DB name
DB user
DB password
```

Для первой установки база должна быть **пустой**.

Не используй базу другого сайта: установщик специально останавливается, если видит неизвестные существующие таблицы.

## 4. Установи MuchoCore

Открой:

```text
https://YOUR-DOMAIN/shared-install.php
```

Установщик сначала проверит PHP, расширения, файлы, каталоги и возможность записи.

После отправки формы он дополнительно:

1. подключится к MySQL/MariaDB;
2. проверит версию СУБД;
3. проверит реальные права пользователя через временную таблицу;
4. создаст резервную копию выбранной базы;
5. проверит gzip и SHA-256 backup;
6. выполнит все миграции;
7. создаст или обновит администратора;
8. запишет lock-marker завершённой установки.

Если миграция не сможет стартовать безопасно, перенос не выполняется.

## 5. Проверка

Открой:

```text
https://YOUR-DOMAIN/health
```

Ожидаемый ответ:

```text
1
```

Затем:

```text
https://YOUR-DOMAIN/admin/
```

Логин администратора:

```text
admin
```

Пароль задаётся во время установки.

## 6. Перенос существующего GDPS

После чистой установки открой:

```text
https://YOUR-DOMAIN/admin/?page=migration
```

В shared-hosting режиме Migration Center не использует `proc_open()`, Docker или sudo.

Порядок:

```text
Старая GDPS БД
      ↓
Read-only подключение
      ↓
Проверка схемы
      ↓
Preview количества данных
      ↓
Свежий backup новой MuchoCore БД
      ↓
Проверка backup
      ↓
Миграции MuchoCore
      ↓
Транзакционный импорт
      ↓
Проверка результата
```

Старая база открывается только на чтение.

Автоматически импортируются:

- accounts;
- profiles;
- levels;
- classic scores;
- Platformer scores.

Дополнительные legacy datasets могут быть обнаружены и показаны как `DETECTED`, но не выдаются за перенесённые данные.

### Пароли

Совместимый password hash переносится как есть.

Если старый формат невозможно безопасно использовать, аккаунт получает отметку о необходимости восстановления пароля. Старый пароль не придумывается и не раскрывается.

### База старой GDPS

В поле **Old DB host** указывается именно сервер MySQL/MariaDB, а не адрес сайта.

Например:

```text
127.0.0.1
localhost
mysql.example.com
```

## 7. После установки

Удалить:

```text
public/shared-install.php
```

Установщик также пытается удалить себя автоматически.

Не удаляй:

```text
.env
storage/admin-bootstrap.php
config/cloudsave.key
storage/backups/database/
```

Backup до миграции рекомендуется сохранить отдельно до окончания тестирования нового сервера.

## 8. Ограничения shared hosting

Shared hosting не предоставляет функции, характерные для VPS:

- Docker;
- systemd timers;
- sudo/root operations;
- серверные shell-операции;
- VPS-only restore/ops.

Основные GDPS API, Admin Panel, Cloud Save, миграции и PHP-backup при этом работают в shared режиме.

## 9. Если что-то не работает

Проверяй в таком порядке:

```text
/health
↓
PHP version
↓
PHP extensions
↓
DB host / port / name / user / password
↓
directory permissions
↓
hosting PHP error log
```

Не пытайся открывать `src/`, `vendor/`, `storage/` или `.env` через браузер.

