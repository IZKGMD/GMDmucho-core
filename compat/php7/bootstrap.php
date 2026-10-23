<?php
/** Shared native configuration, connection and private atomic writes. PHP 7.0. */
declare(strict_types=1);

function mucho7_connect($settings)
{
    if (!extension_loaded('pdo_mysql') || !is_array($settings)) {
        throw new RuntimeException('PDO MySQL or configuration unavailable');
    }
    foreach (array('host','port','database','username','password') as $key) {
        if (!isset($settings[$key]) || !is_scalar($settings[$key])) {
            throw new RuntimeException('Incomplete database configuration');
        }
    }
    $host = (string)$settings['host']; $port = (int)$settings['port'];
    $database = (string)$settings['database'];
    if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $host)
        || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
        || $port<1 || $port>65535) { throw new RuntimeException('Invalid database configuration'); }
    $pdo = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4',
        (string)$settings['username'], (string)$settings['password'], array(
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT=>3));
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}

function mucho7_options($settings, $root)
{
    $options = array();
    foreach (array('enable_accounts','enable_registration','enable_writes',
        'enable_cloudsave','enable_social','enable_native_news') as $key) {
        $options[$key] = !empty($settings[$key]);
    }
    $options['site_url'] = isset($settings['site_url']) ? $settings['site_url'] : '';
    $options['auth_pepper'] = isset($settings['auth_pepper']) ? $settings['auth_pepper'] : '';
    $options['news_file'] = $root . '/config/php7-news.json';
    $options['ip'] = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return $options;
}

function mucho7_https()
{
    // Do not trust a client-supplied X-Forwarded-Proto header.
    return (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])==='on')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT']==='443');
}

function mucho7_private_write($path, $contents)
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(12));
    $handle = @fopen($temporary, 'x');
    if (!$handle) { throw new RuntimeException('Private directory is not writable'); }
    @chmod($temporary,0600);
    try {
        $offset = 0; $length = strlen($contents);
        while ($offset<$length) {
            $written = fwrite($handle,substr($contents,$offset));
            if ($written===false || $written===0) { throw new RuntimeException('Private write failed'); }
            $offset += $written;
        }
        if (!fflush($handle)) { throw new RuntimeException('Private flush failed'); }
        fclose($handle); $handle = null;
        if (!rename($temporary,$path)) { throw new RuntimeException('Private rename failed'); }
        @chmod($path,0600);
    } finally {
        if (is_resource($handle)) { fclose($handle); }
        if (is_file($temporary)) { unlink($temporary); }
    }
}
