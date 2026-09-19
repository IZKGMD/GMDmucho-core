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

            if (isset($_POST['registrations_disabled'])) {
                file_put_contents(
                    CONTROL_DIR.'/registrations-disabled.flag',
                    '1'
                );
            } else {
                @unlink(
                    CONTROL_DIR.'/registrations-disabled.flag'
                );
            }

            audit($db,'settings.save');
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
