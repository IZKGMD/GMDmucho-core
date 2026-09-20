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

    $site = [
        'name' => Settings::string('MUCHO_SITE_NAME', 'Mucho GDPS'),
        'tagline' => Settings::string('MUCHO_SITE_TAGLINE', 'Powered by MuchoCore'),
        'description' => Settings::string(
            'MUCHO_SITE_DESCRIPTION',
            'Custom Geometry Dash private server powered by MuchoCore.'
        ),
        'logo' => Settings::string('MUCHO_SITE_LOGO', 'MuchoGDPS'),
        'accent' => Settings::string('MUCHO_SITE_ACCENT', '#7768ff'),
        'accent2' => Settings::string('MUCHO_SITE_ACCENT2', '#43d7cf'),
        'github' => Settings::string('MUCHO_SITE_GITHUB_URL', ''),
        'discord' => Settings::string('MUCHO_SITE_DISCORD_URL', ''),
        'telegram' => Settings::string('MUCHO_SITE_TELEGRAM_URL', ''),
        'client' => Settings::string('MUCHO_SITE_CLIENT_URL', ''),
        'copyright' => Settings::string(
            'MUCHO_SITE_COPYRIGHT',
            'Copyright © 2026 IZK'
        ),
    ];

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
            <h2>Site Builder</h2>

            <p class="muted">
                Это готовый конструктор главной страницы.
                HTML редактировать не нужно.
            </p>

            <div style="display:grid;gap:12px">

                <label>
                    <small>Site name</small><br>
                    <input name="site_name" maxlength="64" value="<?=h($site['name'])?>" style="width:100%;margin-top:5px">
                </label>

                <label>
                    <small>Tagline</small><br>
                    <input name="site_tagline" maxlength="120" value="<?=h($site['tagline'])?>" style="width:100%;margin-top:5px">
                </label>

                <label>
                    <small>Description</small><br>
                    <textarea name="site_description" maxlength="240" style="width:100%;min-height:90px;margin-top:5px"><?=h($site['description'])?></textarea>
                </label>

                <label>
                    <small>Logo text</small><br>
                    <input name="site_logo" maxlength="32" value="<?=h($site['logo'])?>" style="width:100%;margin-top:5px">
                </label>

                <div class="row">
                    <label>
                        <small>Accent</small><br>
                        <input type="text" name="site_accent" maxlength="7" value="<?=h($site['accent'])?>">
                    </label>

                    <label>
                        <small>Accent 2</small><br>
                        <input type="text" name="site_accent2" maxlength="7" value="<?=h($site['accent2'])?>">
                    </label>
                </div>

                <label>
                    <small>GitHub URL</small><br>
                    <input type="url" name="site_github" maxlength="512" value="<?=h($site['github'])?>" placeholder="https://github.com/...">
                </label>

                <label>
                    <small>Discord URL</small><br>
                    <input type="url" name="site_discord" maxlength="512" value="<?=h($site['discord'])?>" placeholder="https://discord.gg/...">
                </label>

                <label>
                    <small>Telegram URL</small><br>
                    <input type="url" name="site_telegram" maxlength="512" value="<?=h($site['telegram'])?>" placeholder="https://t.me/...">
                </label>

                <label>
                    <small>Client download URL</small><br>
                    <input type="url" name="site_client" maxlength="512" value="<?=h($site['client'])?>" placeholder="https://...">
                </label>

                <label>
                    <small>Project copyright</small><br>
                    <input name="site_copyright" maxlength="120" value="<?=h($site['copyright'])?>" style="width:100%;margin-top:5px">
                </label>

            </div>
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
