<?php declare(strict_types=1);requirePermission('client.manage'); ?>
<section class="card">
<h2>Патчер клиентов</h2>
<p>Загрузи исходный Geometry Dash, укажи домен и скачай готовый клиент. APK подписывается на этом хостинге; оригинальный файл сохраняется.</p>
<form id="sharedPatcher" data-endpoint="<?=h($script)?>" data-csrf="<?=h(csrf())?>">
<p><label for="sharedPatcherFile">Оригинальный APK или EXE</label><br><input id="sharedPatcherFile" type="file" accept=".apk,.exe" required></p>
<p><label for="sharedPatcherServer">Домен сервера</label><br><input id="sharedPatcherServer" type="url" required value="<?=h(isset($settings['site_url'])?$settings['site_url']:'')?>" placeholder="https://gdps.example.com"></p>
<p><label><input id="sharedPatcherAlongside" type="checkbox" checked> Установить APK рядом с оригинальной игрой (GD 2.209 ARM64)</label></p>
<p><button id="sharedPatcherStart" type="submit">Подготовить клиент</button> <button id="sharedPatcherReset" type="button" class="gray">Сбросить задачу</button></p>
<progress id="sharedPatcherProgress" max="100" value="0" aria-label="Подготовка клиента"></progress>
<p id="sharedPatcherStatus" role="status" aria-live="polite">Выбери файл для начала.</p>
<p><a id="sharedPatcherDownload" hidden>Скачать готовый клиент</a></p>
<pre id="sharedPatcherReport" hidden></pre>
</form>
<details><summary>Совместимость и обновления</summary>
<p>APK до 512 МиБ, EXE до 64 МиБ. Для APK нужны PHP OpenSSL, zlib и 64-битный PHP; Java, shell, ZipArchive и VPS не нужны. Известные сборки GD 2.209 проверяются по SHA-256, неизвестные современные сборки отклоняются.</p>
<p>Одинаковый APK-ключ используется для следующих сборок этого сервера. Сохраняй приватный <code>config/php7-apk-signer.json</code> вместе с резервными копиями. Клиент, подписанный другим ключом, нельзя обновить поверх установленного. Опция установки рядом создаёт отдельное приложение.</p>
<p>Патчер меняет адреса API; защита лицензии и другие функции клиента не изменяются. Путь <code>/mc</code> к домену добавлять не нужно — клиент использует маршруты в корне домена.</p>
</details>
</section>
<script src="/assets/php7-shared-patcher.js" defer></script>
