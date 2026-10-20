<?php
/**
 * Experimental GD login/registration on native PHP 7.
 * Existing MuchoCore account hashes and wire results are retained.
 * No credential bypass or account ID / IP-only authentication.
 *
 * Enable only for controlled disposable tests, never PHP7 public production.
 */
declare(strict_types=1);

function mucho7_auth_event(PDO $pdo, $action, $username, $ip, $pepper)
{
    if (!is_string($pepper) || strlen($pepper) < 32) {
        throw new RuntimeException('Missing auth pepper');
    }
    $ipHash = hash_hmac('sha256', (string)$ip, $pepper);
    $nameHash = hash_hmac('sha256', strtolower((string)$username), $pepper);
    // Limit attempts per IP and account over the last ten minutes.
    // The DB event log is intentionally bounded by explicit cleanup.
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM php7_auth_events
         WHERE action=:action AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)
         AND (ip_hash=:ip OR username_hash=:name)'
    );
    $check->execute(array(':action'=>$action, ':ip'=>$ipHash, ':name'=>$nameHash));
    $count = (int)$check->fetchColumn();
    if ($count >= ($action === 'login' ? 10 : 3)) {
        return false;
    }
    $insert = $pdo->prepare(
        'INSERT INTO php7_auth_events(action,ip_hash,username_hash)
         VALUES(:action,:ip,:name)'
    );
    $insert->execute(array(':action'=>$action, ':ip'=>$ipHash, ':name'=>$nameHash));
    // Avoid a table growing indefinitely on low-resource shared hosting.
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM php7_auth_events
            WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
    }
    return true;
}

function mucho7_register(PDO $pdo, $input, $ip, $pepper)
{
    $username = isset($input['userName']) && is_string($input['userName'])
        ? trim($input['userName']) : '';
    $password = isset($input['password']) && is_string($input['password'])
        ? $input['password'] : '';
    $email = isset($input['email']) && is_string($input['email'])
        ? trim($input['email']) : '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $username)) {
        return '-4';
    }
    if (strlen($password) < 8 || strlen($password) > 72 ||
        strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '-1';
    }
    if (!mucho7_auth_event($pdo, 'register', $username, $ip, $pepper)) {
        return '-1';
    }
    $check = $pdo->prepare('SELECT 1 FROM accounts WHERE username=:name LIMIT 1');
    $check->execute(array(':name'=>$username));
    if ($check->fetchColumn() !== false) { return '-2'; }
    $check = $pdo->prepare('SELECT 1 FROM accounts WHERE email=:email LIMIT 1');
    $check->execute(array(':email'=>$email));
    if ($check->fetchColumn() !== false) { return '-3'; }

    $passHash = password_hash($password, PASSWORD_BCRYPT, array('cost'=>12));
    $gjp2Hash = password_hash(sha1($password . 'mI29fmAnxgTs'), PASSWORD_BCRYPT,
        array('cost'=>12));
    if (!is_string($passHash) || !is_string($gjp2Hash)) {
        return '-1';
    }
    $pdo->beginTransaction();
    try {
        // Always assign the least privileged role.
        $stmt = $pdo->prepare(
            "INSERT INTO accounts (username,email,password_hash,gjp2_hash,role_id)
             VALUES (:name,:email,:password,:gjp2,
             (SELECT id FROM roles WHERE code='user' LIMIT 1))"
        );
        $stmt->execute(array(
            ':name'=>$username, ':email'=>$email,
            ':password'=>$passHash, ':gjp2'=>$gjp2Hash
        ));
        $id = (int)$pdo->lastInsertId();
        $profile = $pdo->prepare('INSERT INTO profiles(account_id) VALUES(:id)');
        $profile->execute(array(':id'=>$id));
        $pdo->commit();
        return '1';
    } catch (Exception $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Unique constraints protect against concurrent duplicate registration.
        return '-1';
    }
}

function mucho7_login(PDO $pdo, $input, $ip, $pepper)
{
    $username = isset($input['userName']) && is_string($input['userName'])
        ? trim($input['userName']) : '';
    $password = isset($input['password']) && is_string($input['password'])
        ? $input['password'] : '';
    $gjp2 = isset($input['gjp2']) && is_string($input['gjp2'])
        ? $input['gjp2'] : '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D',$username)
        || strlen($password)>256 || strlen($gjp2)>256
        || ($password==='' && $gjp2==='')) { return '-1'; }
    if (!mucho7_auth_event($pdo, 'login', $username, $ip, $pepper)) {
        return '-1';
    }
    $find = $pdo->prepare(
        'SELECT a.account_id,a.password_hash,a.gjp2_hash,a.is_active,a.is_banned,
                p.user_id
         FROM accounts a LEFT JOIN profiles p ON p.account_id=a.account_id
         WHERE a.username=:name LIMIT 1'
    );
    $find->execute(array(':name'=>$username));
    $row = $find->fetch(PDO::FETCH_ASSOC);
    $dummy = '$2y$12$1/MaE58zhdgQZQpBkgNBhuqdaaih/yUIuXVGBWgthDWALLCBtilDC';
    if (!$row) {
        password_verify($password !== '' ? $password : $gjp2, $dummy);
        return '-1';
    }
    if ((int)$row['is_banned'] === 1 || (int)$row['is_active'] !== 1) {
        password_verify($password !== '' ? $password : $gjp2, $dummy);
        return '-1';
    }
    $passHash = (string)$row['password_hash'];
    $storedGjp2 = (string)$row['gjp2_hash'];
    $good = false;
    if ($password !== '') {
        $good = password_verify($password, $passHash);
        if (!$good && $storedGjp2 !== '') {
            $good = password_verify(sha1($password . 'mI29fmAnxgTs'), $storedGjp2)
                || password_verify(sha1($password . 'mPtFakeGJKeyPair'), $storedGjp2);
        }
        if (!$good && preg_match('/^[0-9a-f]{40}$/iD', $password)
            && $storedGjp2 !== '') {
            $good = password_verify($password, $storedGjp2);
        }
    }
    if (!$good && $gjp2 !== '' && preg_match('/^[0-9a-f]{40}$/iD', $gjp2)
        && $storedGjp2 !== '') {
        $good = password_verify($gjp2, $storedGjp2);
    }
    if (!$good) { return '-1'; }
    $accountId = (int)$row['account_id'];
    $userId = (int)$row['user_id'];
    if ($userId <= 0) {
        $insert = $pdo->prepare('INSERT IGNORE INTO profiles(account_id) VALUES(:id)');
        $insert->execute(array(':id'=>$accountId));
        $fetch = $pdo->prepare('SELECT user_id FROM profiles WHERE account_id=:id');
        $fetch->execute(array(':id'=>$accountId));
        $userId = (int)$fetch->fetchColumn();
        if ($userId <= 0) { return '-1'; }
    }
    try {
        $mark = $pdo->prepare(
            'UPDATE accounts SET last_login_at=CURRENT_TIMESTAMP WHERE account_id=:id'
        );
        $mark->execute(array(':id'=>$accountId));
    } catch (Exception $ignored) {
        // Post-login accounting must never change a correctly authenticated result.
    }
    return $accountId . ',' . $userId;
}

function mucho7_authenticate(PDO $pdo, $accountId, $credential)
{
    if ($accountId <= 0 || !is_string($credential) ||
        strlen($credential) > 1024 || $credential === '') {
        return false;
    }
    $find = $pdo->prepare(
        'SELECT account_id,password_hash,gjp2_hash,is_active,is_banned
         FROM accounts WHERE account_id=:id LIMIT 1'
    );
    $find->execute(array(':id'=>$accountId));
    $row = $find->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['is_active'] !== 1 || (int)$row['is_banned'] === 1) {
        return false;
    }
    if (password_verify($credential, (string)$row['password_hash'])) {
        return true;
    }
    return preg_match('/^[a-f0-9]{40}$/iD', $credential)
        && (string)$row['gjp2_hash'] !== ''
        && password_verify($credential, (string)$row['gjp2_hash']);
}
