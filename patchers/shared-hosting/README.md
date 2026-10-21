# Shared hosting / 5v.pl — что делать с длинным доменом

На 5v.pl MuchoCore PHP 7 может лежать в `/mc/`, а Windows GD 2.209 сохраняет адреса сервера в **строках фиксированной длины**. Если в адрес не помещается `/database/`, патчер использует `/a/`, иногда — маршрут без префикса.

**Пример для отдельного тестового сайта** `longgdpsname.5v.pl` (фактически проверены `/a/getGJLevels21.php` и `/mc/health`):

```apache
# In the SITE ROOT .htaccess, not inside /mc:
RewriteEngine On
RewriteRule ^a/[A-Za-z0-9_-]+\.php$ mc/public/php7.php [QSA,L,NC]
RewriteRule ^checkIfServerOnline\.php$ mc/public/php7.php [QSA,L,NC]
```

*Добавьте* правила до общих правил маршрутизации и сохраните существующие записи. Это пример для изолированного сайта, **не** универсальное разрешение всех PHP-путей.

**Важно:** пример отображает только транспортную совместимость. Большинство конечных точек GD 2.209 в PHP 7-порте могут возвращать `-1`. Не включайте регистрацию или настоящие аккаунты на неподдерживаемом PHP 7.0.

Для **конкретного оригинального EXE** лучше генерировать точные правила с помощью PHP 8.2+ на компьютере из корня репозитория:

```sh
php bin/mucho-patch-gd2209.php --input=/path/to/GeometryDash.exe --server=https://longgdpsname.5v.pl --dry-run --apache-snippet=5v-routes.txt
```

После изменения `.htaccess` проверьте маршруты **POST**:

```powershell
curl.exe -sS -X POST -d "gameVersion=22&binaryVersion=51&type=0&page=0" "https://longgdpsname.5v.pl/a/getGJLevels21.php"
```

`-2` означает пустой поиск; строка с названием уровня означает, что поиск ответил. Ни тот, ни другой результат **не доказывает** полноценную совместимость игры.

Никогда не загружайте конфигурацию MySQL или секреты в публичные каталоги.
