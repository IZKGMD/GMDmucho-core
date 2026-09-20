<?php
declare(strict_types=1);

/*
 * MuchoCore Admin — Simple Server Settings
 * Copyright (C) 2026 IZK
 */

use MuchoCore\Core\Settings;

function renderMuchoSettingsPage(PDO $db): void
{
    requireRank(40);

    $keys = [
        'MUCHO_SERVER_NAME',
        'MUCHO_SERVER_VERSION',
        'MUCHO_REGISTRATION_ENABLED',
        'MUCHO_LEVEL_UPLOAD_ENABLED',
        'MUCHO_CLOUD_SAVE_MAX_MB',
        'MUCHO_LEVEL_MAX_MB',
        'MUCHO_CUSTOM_CONTENT_URL',
    ];

    $defaults = [
        'MUCHO_SERVER_NAME' => Settings::string('MUCHO_SERVER_NAME', 'MuchoGDPS'),
        'MUCHO_SERVER_VERSION' => Settings::string('MUCHO_SERVER_VERSION', '1.0.1'),
        'MUCHO_REGISTRATION_ENABLED' => Settings::bool('MUCHO_REGISTRATION_ENABLED', true),
        'MUCHO_LEVEL_UPLOAD_ENABLED' => Settings::bool('MUCHO_LEVEL_UPLOAD_ENABLED', true),
        'MUCHO_CLOUD_SAVE_MAX_MB' => Settings::int('MUCHO_CLOUD_SAVE_MAX_MB', 32, 1, 256),
        'MUCHO_LEVEL_MAX_MB' => Settings::int('MUCHO_LEVEL_MAX_MB', 32, 1, 256),
        'MUCHO_CUSTOM_CONTENT_URL' => Settings::string(
            'MUCHO_CUSTOM_CONTENT_URL',
            'https://geometrydashfiles.b-cdn.net'
        ),
    ];

    $accountUrl = Settings::string(
        'MUCHO_ACCOUNT_URL',
        'https://localhost'
    );

    ?>
    <div class="boxgrid">

        <div class="card">
            <h2>Simple server settings</h2>

            <p class="muted">
                Эти значения можно менять без редактирования PHP.
                Изменения применяются после сохранения и переживают обновление проекта.
            </p>

            <form method="post">
                <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="settings-save">
                <input type="hidden" name="return" value="settings">

                <div style="display:grid;gap:12px">

                    <label>
                        <input
                            type="checkbox"
                            name="maintenance"
                            <?=is_file(CONTROL_DIR.'/maintenance.flag') ? 'checked' : ''?>
                        >
                        Maintenance mode
                    </label>

                    <label>
                        <small>Server name</small><br>
                        <input
                            name="server_name"
                            maxlength="64"
                            value="<?=h($defaults['MUCHO_SERVER_NAME'])?>"
                            style="width:100%;margin-top:5px"
                        >
                    </label>

                    <label>
                        <small>Server version</small><br>
                        <input
                            name="server_version"
                            maxlength="32"
                            value="<?=h($defaults['MUCHO_SERVER_VERSION'])?>"
                            style="width:100%;margin-top:5px"
                        >
                    </label>

                    <label>
                        <input
                            type="checkbox"
                            name="registration_enabled"
                            <?=(
                                $defaults['MUCHO_REGISTRATION_ENABLED'] &&
                                !is_file(CONTROL_DIR.'/registrations-disabled.flag')
                            ) ? 'checked' : ''?>
                        >
                        Разрешить регистрацию
                    </label>

                    <label>
                        <input
                            type="checkbox"
                            name="level_upload_enabled"
                            <?=$defaults['MUCHO_LEVEL_UPLOAD_ENABLED'] ? 'checked' : ''?>
                        >
                        Разрешить загрузку уровней
                    </label>

                    <label>
                        <small>Cloud Save limit, MB (1–256)</small><br>
                        <input
                            type="number"
                            name="cloud_save_max_mb"
                            min="1"
                            max="256"
                            step="1"
                            value="<?=h($defaults['MUCHO_CLOUD_SAVE_MAX_MB'])?>"
                            style="width:100%;margin-top:5px"
                        >
                    </label>

                    <label>
                        <small>Level data limit, MB (1–256)</small><br>
                        <input
                            type="number"
                            name="level_max_mb"
                            min="1"
                            max="256"
                            step="1"
                            value="<?=h($defaults['MUCHO_LEVEL_MAX_MB'])?>"
                            style="width:100%;margin-top:5px"
                        >
                    </label>

                    <label>
                        <small>Custom content URL</small><br>
                        <input
                            type="url"
                            name="custom_content_url"
                            maxlength="512"
                            value="<?=h($defaults['MUCHO_CUSTOM_CONTENT_URL'])?>"
                            style="width:100%;margin-top:5px"
                        >
                    </label>

                    <div class="row">
                        <button type="submit">Save settings</button>

                        <button
                            type="submit"
                            class="gray"
                            name="settings_reset"
                            value="1"
                            onclick="return confirm('Сбросить эти настройки к значениям из .env?')"
                        >
                            Reset to .env
                        </button>
                    </div>

                </div>
            </form>
        </div>

        <div class="card">
            <h2>Current connection</h2>

            <p>
                <small>GDPS URL</small><br>
                <b><?=h($accountUrl)?></b>
            </p>

            <p class="muted">
                Домен и база данных остаются системной конфигурацией.
                Здесь меняются только безопасные обычные настройки сервера.
            </p>

            <h3>Available keys</h3>
            <pre><?=h(implode(PHP_EOL, $keys))?></pre>
        </div>

    </div>
    <?php
}
