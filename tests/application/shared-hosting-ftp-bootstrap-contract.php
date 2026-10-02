<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$bootstrap = file_get_contents($root . '/public/ftp-install.php');

if ($bootstrap === false) {
    throw new RuntimeException('Unable to read public/ftp-install.php');
}

foreach ([
    "https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest",
    "MuchoCore-v",
    "-shared-hosting.zip",
    "'digest'",
    "hash_file('sha256'",
    'ZipArchive',
    'assert_clean_target',
    'muchocore/public/shared-install.php',
    'CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_SSL_VERIFYHOST',
    'CURLOPT_FOLLOWLOCATION',
    'X-Frame-Options: DENY',
    'Content-Security-Policy:',
    'session.cookie_httponly',
    'session.cookie_samesite',
    "header('Location: shared-install.php'",
    '@unlink(__FILE__)',
] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        throw new RuntimeException('FTP bootstrap contract missing: ' . $needle);
    }
}

foreach ([
    '$_POST[\'ftp_password\']',
    '$_POST[\'password\']',
    '$_SERVER[\'HTTP_X_FORWARDED_PROTO\']',
    'eval(',
    'shell_exec(',
    'system(',
] as $forbidden) {
    if (str_contains($bootstrap, $forbidden)) {
        throw new RuntimeException('FTP bootstrap contains a forbidden pattern: ' . $forbidden);
    }
}

if (!str_contains($bootstrap, 'Your FTP username and password stay between you and your hosting provider.')) {
    throw new RuntimeException('FTP bootstrap must explicitly state that FTP credentials are not collected.');
}

echo "shared-hosting-ftp-bootstrap-contract: OK\n";
