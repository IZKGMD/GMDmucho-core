<?php
/**
 * Termux / PHP CLI: generate account password reset SQL locally.
 * No server login, FTP credentials, MySQL connection or secret output.
 *
 * Example: php tools/mobile/php7-account-reset.php 2 izk
 * Run the resulting SQL in phpMyAdmin after taking an account DB backup.
 */
declare(strict_types=1);

function mucho7_local_reset_sql($id, $name, $password)
{
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) ||
        (int)$id > 2147483647) {
        throw new InvalidArgumentException('Invalid account ID');
    }
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $name)) {
        throw new InvalidArgumentException('Invalid account username');
    }
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
        throw new InvalidArgumentException('Password must be 8-72 bytes');
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, array('cost'=>12));
    $gjp2 = password_hash(sha1($password.'mI29fmAnxgTs'),
        PASSWORD_BCRYPT,array('cost'=>12));
    if (!is_string($hash) || !is_string($gjp2)) {
        throw new RuntimeException('Could not produce secure hashes');
    }
    // Both user identifiers are validated and hashes contain safe bcrypt chars.
    // Keep existing account_id, user_id and everything except the two hashes.
    return "UPDATE accounts SET password_hash='".$hash
        ."', gjp2_hash='".$gjp2."' WHERE account_id=".(int)$id
        ." AND username='".$name."';\n";
}

function mucho7_local_password($message)
{
    fwrite(STDERR,$message);
    // stty is available on Termux and avoids putting plaintext in history.
    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : false;
    if (!$tty) {
        throw new RuntimeException('Run interactively in Termux (TTY required)');
    }
    system('stty -echo 2>/dev/null');
    try {
        $value = fgets(STDIN);
    } finally {
        system('stty echo 2>/dev/null');
        fwrite(STDERR,"\n");
    }
    if ($value === false) { throw new RuntimeException('Input cancelled'); }
    return rtrim($value,"\r\n");
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if ($argc !== 3) {
            throw new RuntimeException('Usage: php php7-account-reset.php ACCOUNT_ID USERNAME');
        }
        $password = mucho7_local_password('New TEST password (hidden): ');
        $confirmation = mucho7_local_password('Repeat password (hidden): ');
        if (!hash_equals($password,$confirmation)) {
            throw new RuntimeException('Passwords differ');
        }
        unset($confirmation);
        $sql = mucho7_local_reset_sql($argv[1],$argv[2],$password);
        unset($password);
        fwrite(STDOUT,"\nCopy only this SQL into phpMyAdmin (after backup):\n\n".$sql."\n");
        fwrite(STDERR,"Reset SQL generated locally; it was not executed.\n");
    } catch (Exception $error) {
        fwrite(STDERR,'ERROR: '.$error->getMessage()."\n");
        exit(1);
    }
}
