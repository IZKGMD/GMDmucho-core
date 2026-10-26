<?php
/** PHP 7.0+ standalone cryptographic regression; no MySQL or live files. */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/compat/php7/cloudsave.php';
function cryptoCheck($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}
function mustReject($callback, $message)
{
    try { $callback(); }
    catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
$key=random_bytes(32);
$plain="Native binary\0save\xff;21;30;a;a";
$first=mucho7_cloudsave_seal($plain,$key);
$second=mucho7_cloudsave_seal($plain,$key);
cryptoCheck(substr($first,0,8)==='MC7ENC1:','Encrypted format marker missing');
cryptoCheck($first!==$second,'Each save must have an independent IV');
cryptoCheck(strpos($first,$plain)===false,'Raw save leaked into ciphertext');
cryptoCheck(mucho7_cloudsave_unseal($first,$key)===$plain,'Binary save did not roundtrip');
cryptoCheck(mucho7_cloudsave_unseal($second,$key)===$plain,'Second ciphertext did not roundtrip');
cryptoCheck(mucho7_cloudsave_unseal($plain,'')===$plain,'Existing plaintext compatibility lost');
mustReject(function()use($first){mucho7_cloudsave_unseal($first,random_bytes(32));},'Wrong key was accepted');
mustReject(function()use($first,$key){$bad=$first;$bad[60]=chr(ord($bad[60])^1);mucho7_cloudsave_unseal($bad,$key);},'Ciphertext tampering accepted');
mustReject(function()use($first,$key){$bad=$first;$bad[33]=chr(ord($bad[33])^1);mucho7_cloudsave_unseal($bad,$key);},'MAC tampering accepted');
mustReject(function()use($key){mucho7_cloudsave_unseal('MC7ENC1:',$key);},'Truncated ciphertext accepted');
mustReject(function()use($plain){mucho7_cloudsave_seal($plain,'wrong');},'Invalid encryption key accepted');
$large=str_repeat('x',8*1024*1024);
$sealed=mucho7_cloudsave_seal($large,$key);
cryptoCheck(strlen($sealed)<16*1024*1024,'Encrypted save exceeds MEDIUMBLOB');
cryptoCheck(mucho7_cloudsave_unseal($sealed,$key)===$large,'Maximum-size save did not roundtrip');
echo "PHP7_ENCRYPTED_CLOUDSAVE_COMPAT_OK\n";
