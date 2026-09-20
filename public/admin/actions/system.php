<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Actions — System
 *
 * settings-save
 * endpoint-test
 * system-op
 *
 * Extracted from verified legacy dispatcher.
 * Copyright (C) 2026 IZK
 */

if(
    !in_array(
        $action,
        [
            'settings-save',
            'endpoint-test',
            'system-op'
        ],
        true
    )
){
    throw new RuntimeException(
        'Invalid System action.'
    );
}

try {

if ($action==='settings-save') {
            requireRank(40);

            if (isset($_POST['maintenance'])) {
                file_put_contents(
                    CONTROL_DIR.'/maintenance.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/maintenance.flag'
                );
            }

            if (isset($_POST['registration_enabled'])) {
                @unlink(
                    CONTROL_DIR.'/registrations-disabled.flag'
                );
            } else {
                file_put_contents(
                    CONTROL_DIR.'/registrations-disabled.flag',
                    '1'
                );
            }

            if (
                array_key_exists('registration_enabled', $_POST) ||
                array_key_exists('level_upload_enabled', $_POST) ||
                isset($_POST['settings_reset']) ||
                array_key_exists('server_name', $_POST) ||
                array_key_exists('server_version', $_POST) ||
                array_key_exists('cloud_save_max_mb', $_POST) ||
                array_key_exists('level_max_mb', $_POST) ||
                array_key_exists('custom_content_url', $_POST)
            ) {
                $file = CONTROL_DIR.'/settings.json';

                if (isset($_POST['settings_reset'])) {
                    @unlink($file);
                } else {
                    $name = trim((string)($_POST['server_name'] ?? ''));
                    $version = trim((string)($_POST['server_version'] ?? ''));
                    $customUrl = trim((string)($_POST['custom_content_url'] ?? ''));
                    $cloudMb = filter_var(
                        $_POST['cloud_save_max_mb'] ?? null,
                        FILTER_VALIDATE_INT
                    );
                    $levelMb = filter_var(
                        $_POST['level_max_mb'] ?? null,
                        FILTER_VALIDATE_INT
                    );

                    if (
                        $name === '' ||
                        strlen($name) > 64 ||
                        preg_match('/[\\x00-\\x1F\\x7F]/', $name)
                    ) {
                        throw new RuntimeException('Server name is invalid.');
                    }

                    if (
                        $version === '' ||
                        strlen($version) > 32 ||
                        preg_match('/[\\x00-\\x1F\\x7F]/', $version)
                    ) {
                        throw new RuntimeException('Server version is invalid.');
                    }

                    if (
                        !is_int($cloudMb) ||
                        $cloudMb < 1 ||
                        $cloudMb > 256
                    ) {
                        throw new RuntimeException('Cloud Save limit must be 1–256 MB.');
                    }

                    if (
                        !is_int($levelMb) ||
                        $levelMb < 1 ||
                        $levelMb > 256
                    ) {
                        throw new RuntimeException('Level data limit must be 1–256 MB.');
                    }

                    if (
                        filter_var($customUrl, FILTER_VALIDATE_URL) === false ||
                        !preg_match('~^https?://~i', $customUrl) ||
                        strlen($customUrl) > 512
                    ) {
                        throw new RuntimeException('Custom content URL is invalid.');
                    }

                    $settings = [
                        'MUCHO_SERVER_NAME' => $name,
                        'MUCHO_SERVER_VERSION' => $version,
                        'MUCHO_REGISTRATION_ENABLED' => isset($_POST['registration_enabled']) ? '1' : '0',
                        'MUCHO_LEVEL_UPLOAD_ENABLED' => isset($_POST['level_upload_enabled']) ? '1' : '0',
                        'MUCHO_CLOUD_SAVE_MAX_MB' => (string)$cloudMb,
                        'MUCHO_LEVEL_MAX_MB' => (string)$levelMb,
                        'MUCHO_CUSTOM_CONTENT_URL' => $customUrl,
                    ];

                    $tmp = $file.'.tmp';
                    $json = json_encode(
                        $settings,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES |
                        JSON_PRETTY_PRINT |
                        JSON_THROW_ON_ERROR
                    );

                    if (
                        file_put_contents(
                            $tmp,
                            $json.PHP_EOL,
                            LOCK_EX
                        ) === false
                    ) {
                        throw new RuntimeException('Cannot save server settings.');
                    }

                    chmod($tmp, 0640);

                    if (!rename($tmp, $file)) {
                        @unlink($tmp);
                        throw new RuntimeException('Cannot publish server settings.');
                    }
                }
            }

            audit(
                $db,
                'settings.save',
                null,
                [
                    'reset'=>isset($_POST['settings_reset'])
                ]
            );

            flash('Settings applied.');            
        }

elseif ($action==='endpoint-test') {
            requireRank(30);

            $endpoint=trim(
                (string)$_POST['endpoint']
            );

            $raw=trim(
                (string)$_POST['payload']
            );

            parse_str($raw,$data);

            $_SESSION['endpoint_result']=
                postLocal(
                    $endpoint,
                    is_array($data)?$data:[]
                );

            audit(
                $db,
                'endpoint.test',
                $endpoint
            );
        }

elseif ($action==='system-op') {
            requireRank(40);

            $op=(string)$_POST['op'];

            $_SESSION['system_output']=rootOp($op);

            audit($db,'system.'.$op);
            flash('Operation completed.');
        }

} catch(Throwable $e) {

    flash(
        'Error: '.$e->getMessage(),
        'error'
    );
}

$return=(string)(
    $_POST['return']
    ?? $_GET['page']
    ?? 'system'
);

header(
    'Location:/admin/?page='.
    rawurlencode($return)
);

exit;
