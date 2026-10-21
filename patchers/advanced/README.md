# GD 2.209 Windows — расширенный патчер внутри MuchoCore

Для разработчиков, которые хотят **точную диагностику** перед изменением клиента. Это тот же PHP-движок `src/Client/Gd2209Binary.php`, который используется рабочим патчером в админке.

**Требуется PHP 8.2+** на локальном компьютере или VPS, а также оригинальный Windows x64 `GeometryDash.exe` одной из известных сборок.

Проверка без записи EXE:

```powershell
php bin/mucho-patch-gd2209.php --input="C:\Games\Geometry Dash\GeometryDash.exe" --server="https://your-gdps.example" --dry-run --json
```

Патчинг с отчётом и правилами для размещения в `/mc/`:

```powershell
php bin/mucho-patch-gd2209.php --input="C:\Games\Geometry Dash\GeometryDash.exe" --server="https://your-gdps.example" --output="C:\Games\Geometry Dash\GeometryDash-MuchoCore.exe" --manifest="client-report.json" --apache-snippet="apache-routes.txt"
```

- **`--dry-run`**: проверка оригинальности EXE и всех целевых адресов, ничего не меняет.
- **`--json`**: детальный отчёт по каждому изменённому маршруту.
- **`--manifest`**: запись отчёта в новый JSON.
- **`--apache-snippet`**: генерирует *узко ограниченные* Apache Rewrite-правила для домена с GDPS в `/mc/`.
- **`--output`**: новый пропатченный клиент. Оригинал не перезаписывается.

Указанные пути запускаются **из корня репозитория MuchoCore**, а не из папки `patchers/advanced`.

Патчер поддерживает две конкретные сборки GD 2.209 по SHA-256: 13 и 19 октября 2026 года. Это статическая проверка файла — запуск игры и полная работа GDPS не гарантируются.

Подробная документация: [docs/CLIENT_PATCH_STUDIO.md](../../docs/CLIENT_PATCH_STUDIO.md).
