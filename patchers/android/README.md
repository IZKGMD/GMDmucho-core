# Android — патчинг APK через админку MuchoCore

**Запуск на Windows:** двойной щелчок по `OPEN-ANDROID.cmd`. Введите **HTTPS-адрес вашего полного MuchoCore**; помощник откроет `/admin/?page=clientpatcher`.

1. Войдите в свою админку MuchoCore с правами администратора.
2. Откройте раздел **Android APK Patcher**.
3. Выберите исходный APK, который вы вправе изменять.
4. Укажите HTTPS-адрес своего GDPS (корень домена) и режим установки рядом с оригинальной игрой, если это доступно для вашего APK.
5. Дождитесь загрузки, патчинга и подписи APK. Скачайте готовый файл, проверьте версию/архитектуру и протестируйте на своём устройстве.

Веб-патчер уже находится в `public/admin/android-client-patcher-module.php` и использует `src/Client/AndroidClientPatcher.php`. **Мы не дублируем его**, чтобы не расходились алгоритмы проверки APK и подписи.

**Важно:** экспериментальный нативный PHP 7-порт MuchoCore на 5v.pl **не** содержит полнофункциональную админку и APK-патчер. Для Android требуется полноценный MuchoCore (PHP 8.2+) либо другой совместимый инструмент. Эта папка не притворяется отдельным офлайн APK-патчером.

Не публикуйте в репозитории игровые APK, закрытые ключи подписи, пароли или токены.

## Geometry Dash 1.0–1.81 on PHP 8

In **Admin → Client Patcher → Android APK Patcher**, select the original version
or leave auto-detection enabled. The patcher checks the executable contents before
editing the server URLs. The recognized builds are 1.0, 1.1, 1.20, 1.30, 1.40,
1.50, 1.60, 1.70, and 1.81. This selection applies to that build job. Other
clients can still connect using their own protocol versions.

The result has two downloads for a recognized legacy build. Use the signed APK on
compatible Android systems, or extract the Windows ZIP with the same APK and
`Start-GD.cmd`. The Windows launcher uses an existing BlueStacks 5 Nougat 32-bit
instance and starts Geometry Dash directly after the first APK install. The ZIP
contains setup instructions. Build 1.0 through 1.40 contains only ARM code and
requires ARM translation on a PC.

All nine APKs target API 16 and contain 32-bit libraries. Android 15 and later
block installation at this target level, and an ARM64-only phone also needs an
ARM64 game library. Signing the APK does not change either requirement.

Legacy network redirects use the original endpoint names under `/database/`.
Use a short server origin that fits in the client's native URL fields.
