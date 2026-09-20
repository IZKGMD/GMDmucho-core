# Простые настройки MuchoCore

Главное правило: **не надо знать PHP**.

Открой файл `.env` в корне проекта.

Меняй только значения справа от `=`.

## Самое важное

`MUCHO_SERVER_NAME=MuchoGDPS` — название сервера.

`MUCHO_SERVER_VERSION=1.0.1` — версия сервера.

`MUCHO_REGISTRATION_ENABLED=1`

- `1` — регистрация включена.
- `0` — регистрация выключена.

`MUCHO_LEVEL_UPLOAD_ENABLED=1`

- `1` — игроки могут загружать уровни.
- `0` — загрузка уровней выключена.

`MUCHO_CLOUD_SAVE_MAX_MB=32` — максимальный размер Cloud Save.

`MUCHO_LEVEL_MAX_MB=32` — максимальный размер данных уровня.

`MUCHO_CUSTOM_CONTENT_URL=https://geometrydashfiles.b-cdn.net` — адрес дополнительного контента.

## Пример

```text
MUCHO_SERVER_NAME=ParoGDPS
MUCHO_SERVER_VERSION=1.0.2
MUCHO_REGISTRATION_ENABLED=1
MUCHO_LEVEL_UPLOAD_ENABLED=1
MUCHO_CLOUD_SAVE_MAX_MB=16
MUCHO_LEVEL_MAX_MB=32
```

На VPS обычные настройки также можно менять прямо в админке:

```text
Admin → Settings
```

Админка хранит такие изменения отдельно от `.env`, поэтому обновление проекта не затирает их.

Кнопка **Reset to .env** удаляет отдельные значения админки и возвращает настройки к `.env`.

Сохрани `.env` и перезапусти сервер, если меняешь его вручную.

## Что не трогать без причины

Не меняй `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, если не понимаешь настройки базы данных.

Пароль администратора хранится отдельно в `.secrets/admin_password`.

Настройки из админки хранятся в:

```text
/var/lib/muchocore-control/settings.json
```

Этот файл не нужно редактировать вручную.

## Итог

```text
Хочу изменить настройку
        ↓
Открываю .env
        ↓
Меняю текст/число
        ↓
Сохраняю
        ↓
Перезапускаю сервер
```
