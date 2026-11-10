# MuchoCore Remote Bridge

Удалённый мост между ChatGPT Work, VPS muchogdps.space и твоим Windows-ПК.

## Архитектура

- VPS: отдельный FastAPI-контейнер remote-bridge, SQLite, веб-панель /remote/.
- Windows: видимый Python Agent, который сам устанавливает исходящие HTTPS-запросы к VPS.
- ChatGPT Work: открывает защищённую веб-панель через Cloud Browser; локальный MCP/Desktop не требуется.
- 5v.pl FTPS: отдельная capability будет добавлена следующим этапом. Этот агент не подключается к FTP автоматически.

Входящие соединения к Windows не открываются. Агент не устанавливается как скрытая служба и не получает повышенные права.

## Возможности первой фазы

- статус Windows, CPU/RAM и краткий список процессов;
- скриншот рабочего стола по отдельному локальному подтверждению;
- просмотр и чтение файлов только внутри выбранных пользователем рабочих папок;
- запись UTF-8-файла с резервной копией и локальным подтверждением;
- запуск приложений только из локального allowlist;
- PowerShell-команды после локального подтверждения; опасные команды требуют дополнительной фразы;
- управление мышью и клавиатурой с подтверждением каждого действия;
- FTPS к 5v.pl: просмотр каталогов, чтение текстовых файлов, загрузка/скачивание, mkdir/rename, обратимое перемещение файла в backup;
- резервная копия дерева FTPS с SHA-256-манифестом и сверкой содержимого перед очисткой настроенного каталога;
- журнал заданий и аудита на VPS.

Никакого скрытого захвата экрана, кейлоггинга, фоновой записи, произвольного автозапуска или обхода UAC нет.

## VPS deployment

Сервис не публикует порт наружу. Он доступен только через внутреннюю Docker-сеть Caddy и только на MUCHOCORE_SITE_HOST.

После review и merge:
1. docker compose build remote-bridge
2. docker compose up -d remote-bridge
3. Инициализировать пароль панели:
   docker compose exec -it remote-bridge python -m remote_bridge.cli init-admin
4. Создать одноразовый pairing token:
   docker compose exec remote-bridge python -m remote_bridge.cli pair-agent --name Edward-PC
5. Открыть https://muchogdps.space/remote/.

Первый запуск требует пароля панели длиной 16+ символов. Пароль не хранится в репозитории и не должен передаваться в чат.

## FTPS 5v.pl

После сопряжения Windows Agent запусти START_FTP_SETUP.cmd. Введи FTPS host, порт, логин, пароль и точный remote root из панели 5v.pl. Пароль сохраняется в Windows Credential Manager, не в agent.json. Требуется explicit FTPS с проверкой TLS-сертификата и защищённым data channel; plaintext FTP не используется.

FTPS actions доступны в веб-панели: ftp.status, ftp.list, ftp.read_text, ftp.upload_file, ftp.download_file, ftp.mkdir, ftp.rename, ftp.delete_file, ftp.backup_tree, ftp.preview_clear_root и ftp.clear_root. Upload/download/mkdir/rename/delete требуют локального подтверждения. ftp.delete_file перемещает файл в резервное имя, а не удаляет его.

Полный сброс разрешён только при заданном непустом remote root, локальной полной копии файлов, проверке SHA-256 каждого файла и точной фразе CLEAR 5V <backup_id>. FTP backup не содержит MySQL: экспорт базы делается отдельно через панель хостинга. После очистки сам настроенный root-каталог остаётся.

## Windows Agent

1. Распаковать папку remote_bridge на Windows.
2. Установить Python 3.11+.
3. Запустить START_SETUP.cmd.
4. Ввести одноразовый pairing token непосредственно в локальном окне.
5. Выбрать только те папки, к которым разрешён доступ.
6. Запустить START_AGENT.cmd.

Agent token сохраняется в Windows Credential Manager. Агент работает в видимой консоли. Закрытие окна или Ctrl+C прекращает соединение.

## Примеры команд панели

- pc.status — payload {}.
- pc.screenshot — payload {}.
- pc.files.list — {"root_index":0,"path":""}.
- pc.files.read — {"root_index":0,"path":"README.md"}.
- pc.files.write — {"root_index":0,"path":"notes.txt","content":"hello"}.
- pc.app.launch — {"name":"notepad"}.
- pc.terminal.run — {"root_index":0,"cwd":"","command":"git status"}.
- pc.desktop.click — {"x":500,"y":300,"button":"left"}.
- pc.desktop.type — {"text":"hello"}.
- pc.desktop.hotkey — {"keys":["ctrl","s"]}.
- pc.desktop.scroll — {"amount":-3}.

## Safety model

- Agent token и admin session независимы.
- Pairing token одноразовый, срок жизни 15 минут.
- Панель использует Argon2-хеш пароля и HttpOnly/Secure/SameSite session cookie.
- Изменяющие операции панели требуют CSRF token.
- Скриншоты, desktop input, запуск приложений, запись файлов и терминал требуют локального подтверждения.
- Terminal работает от имени текущего Windows-пользователя, не elevated.
- Доступ к файлам ограничен явно выбранными корнями.
- Входящий порт на Windows не открывается.
- Задания истекают через 5 минут; офлайн-агент не получает новые задания.
- Агент можно отозвать через панель.

## Граница реализации

В этой фазе добавлены VPS broker, веб-панель, авторизация, pairing и очередь заданий. Следом добавляются Windows Agent, FTPS-модуль и сквозные тесты. Не выкатывать на production до завершения всех проверок.
