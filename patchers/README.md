# MuchoCore — патчеры клиентов Geometry Dash

**На Windows запустите [START-PATCHER.cmd](START-PATCHER.cmd):** откроется простое меню Windows / Android / Инструкция.\n\n**Или выберите свою платформу прямо здесь.** Здесь собраны понятные точки входа для патчинга клиентов. Проверенные алгоритмы остаются в `src/Client/` и `tools/client/` — в этой папке нет несовместимых копий движков.

| Что нужно | Куда нажать | Требования |
| --- | --- | --- |
| Windows, локально | [**windows/START-WINDOWS.cmd**](windows/START-WINDOWS.cmd) | Windows + Python 3, оригинальный `GeometryDash.exe` |
| Android, через админку | [**android/OPEN-ANDROID.cmd**](android/OPEN-ANDROID.cmd) | Доступ администратора MuchoCore PHP 8.2+, свой APK |
| Windows или Android, через браузер | **`https://ВАШ-ДОМЕН/admin/?page=clientpatcher`** | Развёрнутый полный MuchoCore PHP 8.2+ |
| Windows GD 2.209, точная диагностика | [**advanced/README.md**](advanced/README.md) | PHP 8.2+, оригинальный EXE |
| Длинный домен, например 5v.pl | [**shared-hosting/README.md**](shared-hosting/README.md) | Настройка маршрутов сайта |

## За 3 шага: Windows

1. Откройте `windows/START-WINDOWS.cmd` двойным щелчком (или перетащите оригинальный EXE на него).
2. Укажите **свой HTTPS-домен** без `/database`, `/a` или `/mc`; выберите оригинальный `GeometryDash.exe`.
3. Нажмите **Patch client**. Получите **новый** `GeometryDash-MuchoCore.exe` (или файл с номером) и `*.patch-report.txt` рядом с исходником. Оригинал не перезаписывается.

Для Windows-формы нужен установленный **Python 3**, доступный через `py -3` или `python`. Никаких FTP-паролей или паролей игроков не требуется.

## Android

Полный MuchoCore уже содержит веб-патчер APK в админке. Откройте `android/OPEN-ANDROID.cmd` и введите адрес **вашей админки**. Выберите **Android APK Patcher**, загрузите свой APK и скачайте готовый результат. Подробнее: [android/README.md](android/README.md).

## Важно про GD 2.209

- **Известные исходные Windows EXE:** 13 октября 2026 (111 API URL) и 19 октября 2026 (113 API URL). Распознаются по SHA-256.
- Патчер изменяет **адреса серверных API**, а не добавляет отсутствующие функции на сервер.
- На экспериментальном PHP 7-хостинге (например, 5v.pl) многие игровые API ещё не реализованы. Работающий поиск уровней **не означает** полной совместимости 2.209.
- Патчинг Windows и Android — разные процессы. **Не загружайте APK в Windows-патчер.**
- Используйте собственную законно полученную копию игры. В репозитории нет коммерческих исполняемых файлов игры.

## Если что-то не работает

Откройте соответствующий `README.md` в папке платформы и пришлите **ошибку и версию клиента**, но **не** FTP-пароль, пароль MySQL, секрет администратора или игровые учётные данные.

**English:** Windows: double-click `windows/START-WINDOWS.cmd`; select your original EXE and enter the HTTPS **origin**. Android: use the authenticated PHP 8.2+ admin APK patcher. The original game is preserved; GD 2.209 client compatibility is build-specific.
