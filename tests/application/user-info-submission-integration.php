<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\UserInfoController;
use MuchoCore\Account\UserInfoSubmissionService;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';

if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('User-info integration requires isolated CI MariaDB.');
}

$connect = static fn(?string $database): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4',
    'root',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$check = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('User-info integration: ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
};

$name = 'mc_user_info_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
$db = null;

try {
    $server->exec(
        'CREATE DATABASE ' . $name .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $db = $connect($name);

    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL DEFAULT \'\',
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');

    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        stars INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    $migration = require dirname(__DIR__, 2) .
        '/database/migrations/20261110_001_gd2209_user_info.php';
    $migration($db);

    $insert = $db->prepare(
        'INSERT INTO accounts
            (account_id,username,password_hash,gjp2_hash,is_active,is_banned)
         VALUES (:id,:username,:password,:gjp2,:active,:banned)'
    );

    $addAccount = static function (
        int $id,
        string $username,
        string $plainPassword,
        int $active = 1,
        int $banned = 0
    ) use ($insert): string {
        $gjp2 = sha1($plainPassword . 'mI29fmAnxgTs');
        $insert->execute([
            'id' => $id,
            'username' => $username,
            'password' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'gjp2' => password_hash($gjp2, PASSWORD_DEFAULT),
            'active' => $active,
            'banned' => $banned,
        ]);
        return $gjp2;
    };

    $gjp2 = $addAccount(1, 'InfoOwner', 'user-info-password');
    $bannedGjp2 = $addAccount(2, 'BannedInfo', 'banned-info-password', 1, 1);
    $inactiveGjp2 = $addAccount(3, 'InactiveInfo', 'inactive-info-password', 0, 0);

    $db->exec('INSERT INTO profiles (account_id,stars) VALUES (1,17),(2,0),(3,0)');

    $controller = new UserInfoController(
        new UserInfoSubmissionService(
            $db,
            new AccountAuthenticator($db)
        )
    );

    $send = static function (array $fields) use ($controller): Response {
        return $controller->submit(new Request(
            'POST',
            '/submitGJUserInfo.php',
            [],
            $fields,
            ['REMOTE_ADDR' => '192.0.2.10']
        ));
    };

    $payload = '12,87,1|33,100,2|91,45,1';
    $validFields = [
        'accountID' => '1',
        'gjp2' => $gjp2,
        'levelsInfo' => $payload,
        'secret' => 'Wmfv3899gc9',
        'gameVersion' => '22',
        'binaryVersion' => '51',
        'udid' => 'test-device-1',
    ];

    $first = $send($validFields);
    $check($first->status === 200 && $first->body === '1',
        'authenticated GD 2.209 submission returns protocol success');

    // Some legacy/proxied clients omit version metadata. A GJP2-only request
    // must still select the credential correctly when gjp is absent.
    $versionlessFields = $validFields;
    unset($versionlessFields['gameVersion'], $versionlessFields['binaryVersion']);
    $versionless = $send($versionlessFields);
    $check($versionless->body === '1',
        'GJP2-only submission remains valid without optional version fields');

    $stored = $db->query(
        'SELECT levels_info,payload_sha256
         FROM mucho_account_user_info WHERE account_id=1'
    )->fetch();

    $check(is_array($stored) && $stored['levels_info'] === $payload,
        'opaque levelsInfo payload is preserved byte-for-byte');
    $check(is_array($stored) && hash_equals(
        hash('sha256', $payload),
        (string)$stored['payload_sha256']
    ), 'stored payload digest matches the exact submitted bytes');

    $updatedPayload = '12,91,1|33,100,3';
    $updatedFields = $validFields;
    $updatedFields['levelsInfo'] = $updatedPayload;
    $updated = $send($updatedFields);
    $check($updated->body === '1', 'updated payload is accepted');

    $storedAfterUpdate = (string)$db->query(
        'SELECT levels_info FROM mucho_account_user_info WHERE account_id=1'
    )->fetchColumn();
    $check($storedAfterUpdate === $updatedPayload,
        'new payload atomically replaces the previous snapshot');

    // GD 1.9 clients send a URL-safe Base64 + XOR-wrapped GJP credential.
    $legacyPassword = 'user-info-password';
    $legacyKey = '37526';
    $legacyCipher = '';
    for ($i = 0; $i < strlen($legacyPassword); $i++) {
        $legacyCipher .= $legacyPassword[$i] ^ $legacyKey[$i % strlen($legacyKey)];
    }
    $legacyGjp = rtrim(strtr(base64_encode($legacyCipher), '+/', '-_'), '=');
    $legacyFields = $validFields;
    unset($legacyFields['gjp2']);
    $legacyFields['gjp'] = $legacyGjp;
    $legacyFields['gameVersion'] = '19';
    $legacyFields['binaryVersion'] = '28';
    $legacyFields['levelsInfo'] = 'legacy-gjp-user-info';
    $check($send($legacyFields)->body === '1',
        'legacy URL-safe XOR GJP credential is accepted');
    $check((string)$db->query(
        'SELECT levels_info FROM mucho_account_user_info WHERE account_id=1'
    )->fetchColumn() === 'legacy-gjp-user-info',
        'legacy GJP submission is persisted for the authenticated account');

    $wrongCredential = $validFields;
    $wrongCredential['gjp2'] = str_repeat('0', 40);
    $wrongCredential['levelsInfo'] = 'attacker-controlled';
    $check($send($wrongCredential)->body === '-1',
        'invalid GJP2 is rejected');

    $check($send([
        'accountID' => '0',
        'udid' => 'unregistered-device',
        'levelsInfo' => 'unregistered-payload',
        'gameVersion' => '22',
        'binaryVersion' => '51',
    ])->body === '-1', 'UDID alone cannot bypass account authentication');

    $bannedFields = $validFields;
    $bannedFields['accountID'] = '2';
    $bannedFields['gjp2'] = $bannedGjp2;
    $check($send($bannedFields)->body === '-1', 'banned account is rejected');

    $inactiveFields = $validFields;
    $inactiveFields['accountID'] = '3';
    $inactiveFields['gjp2'] = $inactiveGjp2;
    $check($send($inactiveFields)->body === '-1', 'inactive account is rejected');

    $empty = $validFields;
    $empty['levelsInfo'] = '';
    $check($send($empty)->body === '-2', 'authenticated empty payload uses the native missing-data sentinel');

    $emptyUnauthenticated = $validFields;
    $emptyUnauthenticated['gjp2'] = str_repeat('0', 40);
    $emptyUnauthenticated['levelsInfo'] = '';
    $check($send($emptyUnauthenticated)->body === '-1',
        'invalid credentials are rejected before the missing-payload sentinel');

    $zero = $validFields;
    $zero['levelsInfo'] = '0';
    $check($send($zero)->body === '-2', 'string zero matches the native empty-data sentinel');

    $oversized = $validFields;
    $oversized['levelsInfo'] = str_repeat(
        'x',
        UserInfoSubmissionService::MAX_PAYLOAD_BYTES + 1
    );
    $check($send($oversized)->body === '-1',
        'oversized payload is rejected before database storage');

    $check((int)$db->query(
        'SELECT stars FROM profiles WHERE account_id=1'
    )->fetchColumn() === 17,
        'untrusted user-info payload never changes trusted profile progress');

    $check((int)$db->query(
        'SELECT COUNT(*) FROM mucho_account_user_info'
    )->fetchColumn() === 1,
        'unauthorized and invalid submissions create no extra rows');

    echo "MUCHOCORE_USER_INFO_SUBMISSION_INTEGRATION_OK\n";
} finally {
    $db = null;
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
