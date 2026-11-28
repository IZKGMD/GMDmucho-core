# MuchoCore Legacy Custom Songs 0.2.4

Каталог и MP3 находятся на сервере GDPS. Игрок входит в кабинет со своим аккаунтом Geometry Dash, загружает MP3, указывает название и исполнителя. MuchoCore выдаёт песне ID и показывает её игроку в разделе Your uploads со статусом Pending moderation. После одобрения администратором запись появляется в общем каталоге. Сервер связывает песню с уровнем, когда запрос публикации содержит её `songID`.

В Android-модуле кнопка **Custom Songs** открывает каталог. При выборе трека модуль скачивает MP3, проверяет размер и SHA-256, сохраняет файл в кэш и запускает предпрослушивание. Каталог обновляется при открытии и каждые две минуты, пока окно открыто. Доступно ручное обновление.

Чтобы найти уже привязанную песню, откройте **Custom Songs → Find by level ID**, введите ID опубликованного уровня на том же GDPS и нажмите **Preview**. Для уровней со встроенной музыкой окно покажет, что своей песни у уровня нет. Интеграция с редактором и запуском уровня пока в работе.

## Установка на сервер

| Установка | Что скопировать | Как проверить каталог |
| --- | --- | --- |
| MuchoCore PHP 8. Версия 1.1.1 или новее | Папку `server/legacy-songs/` в `custom/plugins/` | `https://gdps.example/api/plugin/legacy-songs/v1/catalog` |
| MuchoCore PHP 7 Shared. Пример установки в `/mc` | Содержимое `server/shared/mc/` в корень действующей установки `/mc/` по FTP | `https://example.5v.pl/mc/legacy-songs.php?op=catalog` |

Откройте URL каталога. В ответе должны быть `"version":1` и массив `tracks`. Игрок открывает `/dashboard#upload` на PHP 8 или `/mc/dashboard#upload` на Shared, входит под своим аккаунтом GD и загружает MP3. Загрузка ограничена 64 МиБ и одним треком раз в три минуты на аккаунт. Серверу также нужны подходящие ограничения на размер POST-запроса в настройках PHP и веб-сервера. Администратор одобряет песню в разделе Songs. После этого ID появляется в каталоге, а файл скачивается другим игроком. Данные берутся из существующих таблиц `songs` и `levels`.

На PHP 8 файлы раздаются через `/music/` из `storage/music-public`. Если каталог файлов перенесён, укажите его в `MUCHO_LEGACY_SONGS_DIR` и настройте раздачу `/music/` из него. Shared поддерживает пути штатной админки `/mc/public/assets/music/` и хранилища `/mc/music/`. Адрес начинается с фактического пути установки, например `/mc`. Shared требует HTTPS.

## Запросы клиента

| Действие | PHP 8 | PHP 7 Shared |
| --- | --- | --- |
| Открыть каталог | `GET /api/plugin/legacy-songs/v1/catalog` | `GET /mc/legacy-songs.php?op=catalog` |
| Получить трек по ID | `GET /api/plugin/legacy-songs/v1/track?id=175` | `GET /mc/legacy-songs.php?op=track&id=175` |
| Получить трек уровня | `GET /api/plugin/legacy-songs/v1/level?id=42` | `GET /mc/legacy-songs.php?op=level&id=42` |

В ответе на запрос по уровню есть `level_id` и `track` с ID, названием, автором, размером, путём к файлу и SHA-256. Клиент добавляет путь из `track.url` к схеме и хосту сервера. Для публичного уровня без кастомной песни ответ содержит `track: null`. Для удалённых, скрытых и незарегистрированных уровней API возвращает 404. Если связанный трек не одобрен или его файла нет, API также возвращает 404.

## Сборка Android APK

Понадобятся Python 3.8+ и Java 17+ с `keytool` в PATH. Патчер скачивает инструменты при первом запуске и проверяет их контрольные суммы. Исходный APK нужен отдельно.

```powershell
py .\patch_apk.py .\my-random-filename.apk .\GD-Custom-Songs.apk --server https://gdps.example
py .\patch_apk.py .\Geometry.Dash.ver.1.81.build.20.apk .\GD-1.81-Custom-Songs-shared.apk --server https://example.5v.pl/mc --shared
```

Подставьте адрес своего GDPS. `--server` задаёт адрес каталога для модуля. Сетевые функции самой игры должны уже смотреть на тот же GDPS. В итоговом APK сохраняется идентификатор приложения. Сборка подписывается локальным тестовым ключом из `.tools`. Для повторных сборок сохраните ключ и файл пароля. Устанавливайте на тестовое устройство или в эмулятор без другой версии GD с таким идентификатором.

Патчер встраивает Android-модуль в `classes.dex` и адрес GDPS в `assets/muchocore-legacy.properties`. Он определяет сборку по содержимому AndroidManifest.xml, classes.dex и нативных библиотек, независимо от имени APK и порядка или сжатия файлов в архиве. Изменённый код игры или неизвестная сборка получает явную ошибку без попытки патча. На сервере отдельно устанавливается PHP-плагин из папки `server/legacy-songs` либо Shared-файлы из `server/shared/mc`.

| GD | Билд | ABI |
| --- | ---: | --- |
| 1.0 | 2 | `armeabi` |
| 1.1 | 5 | `armeabi` |
| 1.20 | 7 | `armeabi` |
| 1.30 | 10 | `armeabi` |
| 1.40 | 11 | `armeabi` |
| 1.50 | 13 | `armeabi`, `armeabi-v7a`, `x86` |
| 1.60 | 16 | `armeabi`, `armeabi-v7a`, `x86` |
| 1.70 | 17 | `armeabi`, `armeabi-v7a`, `x86` |
| 1.81 | 20 | `armeabi`, `armeabi-v7a`, `x86` |

Распознавание и сборка проверены для всех девяти предоставленных APK. Android APK от других сборок и платформ требуют отдельной проверки структуры и интеграции, даже если номер версии известен.

## Проверка версии 0.2.4

На GD 1.81 build 20 с Android 4.4.2 x86 открыли Custom Songs, ввели ID тестового уровня `42` и запустили предпрослушивание. Сервер получил запрос уровня и отдал MP3. В игре появилось `Playing QA tone 1`. Эмулятор был запущен с выключенным звуковым выводом.

Отдельный тест использовал тот же код загрузки с двумя независимыми кэшами. Полученные MP3 совпали побайтно. Проверили восстановление испорченного кэша, уровень со встроенной музыкой, скрытый уровень и песню без одобрения. APK для 1.0, 1.20, 1.60 и 1.81 собраны и прошли проверку подписи.

## Результаты предыдущих версий

На Android Emulator 4.4 x86 установили APK GD 1.60 build 16, запустили игру и открыли Custom Songs. Треки QA tone появились после запроса к тестовому серверу. Тест предпрослушивания на 1.60 не проводился. APK GD 1.20 build 7 собран, его ZIP-структура и подпись проверены, Android-модуль присутствует, нативная библиотека совпадает с исходной. GD 1.20 содержит только ARM-библиотеку. Доступный Android Emulator отказался запускать ARM-системный образ, поэтому запуск 1.20 в игре пока не подтверждён.

На локальных стендах с PHP 8 и PHP 7.0.33 штатный маршрут публикации MuchoCore принял запрос в формате GD 1.8 с `songID`. На каждом стенде два отдельных HTTP-клиента запросили трек по ID опубликованного уровня, скачали одинаковый MP3 и сверили SHA-256. В режиме Shared проверена установка в подпапке `/mc`. Сборка 1.81 для Shared прошла проверку APK-подписи.

Серверный запрос по уровню принимает песни размером до 64 МиБ, как и кабинет игрока. Штатный запрос публикации GD 1.81 передаёт номер встроенной мелодии в `audioTrack`. Выбор песни в редакторе, отправка `songID` при публикации и воспроизведение при старте уровня требуют отдельных изменений игрового клиента. Сценарий на самом 5v.pl пока не проверен.

## Cocos2d integration for GD 1.81 x86

The experimental native mode uses the original GD 1.81 build 20 x86
library. The selector is installed in the level settings inside the editor.
The local and published level screens, editor pause, and game pause resolve
the song through the same selection and MuchoCore metadata.

Build the native library with the Android NDK on Linux:

```sh
ANDROID_NDK_HOME=/path/to/android-ndk ./android/native/build-x86.sh ./libmuchocore_songs.so
python3 patch_apk.py original-181.apk custom-181.apk \
  --server https://gdps.example \
  --cocos-x86-library ./libmuchocore_songs.so
```

Use a short server origin that fits the original native URL fields. This mode
patches the game's legacy database URLs as well as the song catalog origin.
The client displays an error and exits before initializing the game if the
required native module cannot start.
It writes x86 only. Android ARM and other original GD versions require their
own native bindings and runtime checks. Test using a dedicated x86 environment.

The native selection retains the built-in track as the fallback and stores the
custom ID separately in the game's save dictionary. The upload adapter adds
the selected `songID` to the original upload form. Published levels resolve
the ID and checksum through MuchoCore. A previous Android overlay build is an
independent mode and does not support the native editor selection.

## MuchoCore Music Library

On PHP 8, the client reads `/api/v2/music-library.php`. The same track ID is
used for selection, downloads and level uploads. Create songs through the player
dashboard or `/api/v2/music-upload.php`, then approve them in the admin music
library. Catalogs show approved tracks with a readable local MP3.

An upload can include a Newgrounds Audio ID or track URL alongside an MP3 the
uploader has permission to share. The admin list links to the original, and
`?op=newgrounds&id=12345` returns an approved local track linked to that Audio
ID, with its MuchoCore ID and verified file metadata. The server keeps and
serves the uploaded copy.

The earlier plugin route and the PHP 7 Shared endpoint still serve their
original contract. See [OpenAPI](../../../docs/openapi.yaml) for the PHP 8
catalog and upload operations.
