<?php
declare(strict_types=1);

/*
 * MuchoCore Admin Action Map
 * Copyright (C) 2026 IZK
 */

return [

    'accounts'=>[
        'account-save',
        'password-reset',
        'account-delete'
    ],

    'profiles'=>[
        'profile-save',
        'muchoprofile-save',
        'muchoprofile-token'
    ],

    'levels'=>[
        'level-save'
    ],

    'social'=>[
        'comment-delete',
        'message-delete',
        'relation-delete'
    ],

    'system'=>[
        'settings-save',
        'endpoint-test',
        'system-op'
    ],

    'admins'=>[
        'admin-create',
        'admin-toggle',
        '2fa-generate',
        '2fa-enable',
        '2fa-disable'
    ]

];
