<?php
/**
 * PHP7.0-compatible source-order contract for public native News.
 * php:7.0-cli without PDO MySQL must still be able to serve public news.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/compat/php7/runtime.php';
require dirname(__DIR__, 2) . '/compat/php7/news.php';

$entry = file_get_contents(dirname(__DIR__, 2) . '/public/php7.php');
if (!is_string($entry)) {
    throw new RuntimeException('PHP7 front controller missing');
}
$public = strpos($entry, 'if (in_array($path, array(');
$mysql = strpos($entry, "extension_loaded('pdo_mysql')");
$connect = strpos($entry, 'new PDO(');
if ($public === false || $mysql === false || $connect === false
    || !($public < $mysql && $mysql < $connect)
    || strpos($entry, 'mucho7_dispatch(null, $uri, $method, $_POST, $options)')
       === false) {
    throw new RuntimeException('PHP7 native news must run before MySQL checks');
}
$opts = array(
    'enable_native_news'=>true,
    'site_url'=>'https://linasgdpsprivatese.5v.pl',
    'news_file'=>dirname(__DIR__, 2) . '/compat/php7/news.example.json'
);
$version = mucho7_dispatch(null, '/gd-content/news/news_version.txt',
    'GET', array(), $opts);
if ($version !== '1000001') {
    throw new RuntimeException('Public news version requires no PDO instance');
}
$raw = mucho7_dispatch(null, '/gd-content/news/news.dat',
    'GET', array(), $opts);
$data = base64_decode(strtr($raw, '-_', '+/'), true);
if (!is_string($data) || substr($data, 0, 2) !== "\x1f\x8b") {
    throw new RuntimeException('Native news body unavailable without MySQL');
}
$url = mucho7_dispatch(null, '/a/getCustomContentURL.php',
    'POST', array(), $opts);
if ($url !== 'https://linasgdpsprivatese.5v.pl/gd-content') {
    throw new RuntimeException('Native content URL unavailable without MySQL');
}
$opts['enable_native_news'] = false;
if (mucho7_dispatch(null, '/gd-content/news/news.dat',
    'GET', array(), $opts) !== '-1') {
    throw new RuntimeException('Native News flag can no longer disable feed');
}
echo "PHP7_NATIVE_NEWS_WITHOUT_MYSQL_OK\n";
