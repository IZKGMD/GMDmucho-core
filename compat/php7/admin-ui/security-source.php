<?php
function b32decode(string $secret): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret=strtoupper(
        preg_replace('/[^A-Z2-7]/','',$secret)
    );

    $bits='';

    foreach(str_split($secret) as $c) {
        $p=strpos($alphabet,$c);

        if ($p===false) continue;

        $bits.=str_pad(decbin($p),5,'0',STR_PAD_LEFT);
    }

    $out='';

    foreach(str_split($bits,8) as $byte) {
        if (strlen($byte)===8) {
            $out.=chr(bindec($byte));
        }
    }

    return $out;
}

function totp(string $secret,int $offset=0,$now=null): string
{
    $counter=intdiv($now===null?time():$now,30)+$offset;
    $bin=pack('N*',0).pack('N*',$counter);

    $hash=hash_hmac(
        'sha1',
        $bin,
        b32decode($secret),
        true
    );

    $o=ord(substr($hash,-1))&15;

    $code=(
        ((ord($hash[$o])&127)<<24) |
        ((ord($hash[$o+1])&255)<<16) |
        ((ord($hash[$o+2])&255)<<8) |
        (ord($hash[$o+3])&255)
    ) % 1000000;

    return str_pad((string)$code,6,'0',STR_PAD_LEFT);
}

function verifyTotp(string $secret,string $code): bool
{
    if (!preg_match('/^\d{6}$/',$code)) {
        return false;
    }

    return hash_equals(totp($secret,-1),$code)
        || hash_equals(totp($secret,0),$code)
        || hash_equals(totp($secret,1),$code);
}

function newTotpSecret(): string
{
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out='';

    for($i=0;$i<32;$i++) {
        $out.=$alphabet[random_int(0,31)];
    }

    return $out;
}

function generateAdminRecoveryCodes(PDO $db,int $adminId,int $count=10): array
{
    if ($adminId<=0 || $count<1 || $count>20) {
        throw new RuntimeException('Invalid recovery code request.');
    }

    if (!tableExists($db,'php7_admin_recovery_codes')) {
        throw new RuntimeException('Recovery-code storage is unavailable. Run database migrations first.');
    }

    $db->prepare(
        'DELETE FROM php7_admin_recovery_codes WHERE account_id=:id AND used_at IS NULL'
    )->execute(['id'=>$adminId]);

    $codes=[];

    for ($i=0;$i<$count;$i++) {
        $raw=strtoupper(bin2hex(random_bytes(6)));
        $display=substr($raw,0,4).'-'.substr($raw,4,4).'-'.substr($raw,8,4);

        $q=$db->prepare(
            'INSERT INTO php7_admin_recovery_codes
             (account_id,code_hash)
             VALUES (:admin,:hash)'
        );

        $q->execute([
            'admin'=>$adminId,
            'hash'=>password_hash($display,PASSWORD_DEFAULT)
        ]);

        $codes[]=$display;
    }

    return $codes;
}

function verifyAdminRecoveryCode(PDO $db,int $adminId,string $input): bool
{
    $normalized=strtoupper(trim($input));
    $normalized=preg_replace('/[^A-Z0-9]/','',$normalized) ?? '';

    if ($adminId<=0 || strlen($normalized)!==12 || !tableExists($db,'php7_admin_recovery_codes')) {
        return false;
    }

    $rows=$db->prepare(
        'SELECT id,code_hash
         FROM php7_admin_recovery_codes
         WHERE account_id=:admin
           AND used_at IS NULL
         ORDER BY id
         LIMIT 20'
    );

    $rows->execute(['admin'=>$adminId]);

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $candidate=(string)$row['code_hash'];
        $probe=substr($normalized,0,4).'-'.substr($normalized,4,4).'-'.substr($normalized,8,4);

        if (!password_verify($probe,$candidate)) {
            continue;
        }

        $used=$db->prepare(
            'UPDATE php7_admin_recovery_codes
             SET used_at=UTC_TIMESTAMP()
             WHERE id=:id
               AND used_at IS NULL'
        );
        $used->execute(['id'=>(int)$row['id']]);

        return $used->rowCount()===1;
    }

    return false;
}

function countUnusedAdminRecoveryCodes(PDO $db,int $adminId): int
{
    if ($adminId<=0 || !tableExists($db,'php7_admin_recovery_codes')) {
        return 0;
    }

    $q=$db->prepare(
        'SELECT COUNT(*)
         FROM php7_admin_recovery_codes
         WHERE account_id=:id
           AND used_at IS NULL'
    );
    $q->execute(['id'=>$adminId]);

    return (int)$q->fetchColumn();
}


function totpProvisioningUri(
    string $secret,
    string $account,
    string $issuer
): string {
    $issuer=trim($issuer) !== ''
        ? trim($issuer)
        : 'MuchoCore';

    $account=trim($account) !== ''
        ? trim($account)
        : 'admin';

    return 'otpauth://totp/'.
        rawurlencode($issuer).':'.rawurlencode($account).
        '?secret='.rawurlencode($secret).
        '&issuer='.rawurlencode($issuer).
        '&algorithm=SHA1'.
        '&digits=6'.
        '&period=30';
}
