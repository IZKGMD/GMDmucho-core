# Установка MuchoLoader 0.1.0 для Windows

Это самостоятельный загрузчик на базе Geode 5.11.1 для GD 2.2081.
Пакет не нужно помещать в `geode/mods`: это не мод `.geode`.

1. Закрой Geometry Dash.
2. Распакуй архив полностью.
3. Открой PowerShell в папке с `install.ps1` и запусти:

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1 -GameDir "C:\Games\Geometry Dash"
```

Укажи настоящую папку с `GeometryDash.exe`. Скрипт проверяет SHA-256 всех
файлов до установки и сохраняет заменённые файлы в `.mucholoader-backups`.
Папки нативных модов и их сохранений не заменяются.
Если осталось ожидающее обновление Geode или старый `GeodeBootstrapper.dll`,
скрипт попросит сначала запустить и закрыть игру: штатная миграция должна
закончиться до установки нового загрузчика.

После запуска игры открой кнопку **MUCHO**. На странице **Plugins** видны
статусы Python-плагинов; **Buttons** показывает их кнопки. В комплекте есть
пример Hello Python. Он создаёт кнопку приветствия и цветную вспышку при
открытии уровня. Отключить пример можно кнопкой Off.

Свои Python-плагины положи в `geode\mucholoader\plugins\<id>`.
В каждой папке нужны `manifest.json` и `plugin.py`. Смотри README и пример.

Если Python-плагин мешает запуску, запусти игру с аргументом
`--geode:disable-mucho-python`; синтаксис аргумента наследуется от Geode.
Для общей диагностики сохранён штатный безопасный режим Geode (SHIFT при
запуске Windows). В этих режимах Python не запускается.

Чтобы восстановить предыдущие файлы:

```powershell
powershell -ExecutionPolicy Bypass -File .\restore.ps1 -GameDir "C:\Games\Geometry Dash" -BackupDir "C:\Games\Geometry Dash\.mucholoader-backups\ПАПКА_КОПИИ"
```

Запуск в самой игре пока не проверен. Результаты сборки и проверок
совместимости находятся в `compatibility.json`. Совпадение экспортов DLL
не доказывает совместимость со всеми модами.
