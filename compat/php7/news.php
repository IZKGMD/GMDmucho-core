<?php
/**
 * Native Geometry Dash 2.209 News payload generator for experimental PHP7.
 *
 * Original News domain classes, compiled to PHP7. Legacy JSON remains readable
 * until the owner opens News Board and completes the additive SQL migration.
 */
declare(strict_types=1);

foreach (array('NewsValidationException','NewsImage','YouTubeVideo','NewsMediaService','NewsService','NativeNewsFormat','NewsImageResponse') as $newsClass) {
    require_once __DIR__.'/news/'.$newsClass.'.php';
}
require_once __DIR__.'/news-storage.php';

function mucho7_news_length($value) {
    if (function_exists('mb_strlen')) return mb_strlen($value,'UTF-8');
    $count=preg_match_all('/./us',$value,$matches);
    if ($count===false) throw new RuntimeException('Invalid UTF-8 news text.');
    return $count;
}

function mucho7_news_source($filename)
{
    if (!is_string($filename) || $filename === '' || !is_file($filename)
        || !is_readable($filename) || filesize($filename) > 131072) {
        return array('version'=>1000000,'posts'=>array());
    }
    $raw = file_get_contents($filename);
    $parsed = is_string($raw) ? json_decode($raw,true) : null;
    if (!is_array($parsed) || !isset($parsed['posts']) || !is_array($parsed['posts'])
        || !isset($parsed['version']) || !is_int($parsed['version'])
        || $parsed['version'] < 1000000) {
        throw new RuntimeException('Invalid PHP7 native news file.');
    }
    return $parsed;
}

function mucho7_news_b64($value)
{
    return strtr(base64_encode((string)$value), '+/', '-_');
}

function mucho7_news_payload($posts)
{
    if (!is_array($posts)) { throw new InvalidArgumentException('Invalid posts'); }
    return \MuchoCore\News\NativeNewsFormat::textPayload($posts);
}

function mucho7_news_legacy_payload($posts)
{
    // Kept only for validation of the old JSON contract during import.
    $rows = array();
    foreach (array_slice($posts,0,30) as $post) {
        if (!is_array($post)) { throw new RuntimeException('Invalid news post'); }
        $id = isset($post['id']) ? (int)$post['id'] : 0;
        $title = isset($post['title']) && is_string($post['title'])
            ? trim($post['title']) : '';
        $body = isset($post['body']) && is_string($post['body'])
            ? trim($post['body']) : '';
        $date = isset($post['published_at']) && is_string($post['published_at'])
            ? strtotime($post['published_at']) : false;
        $url = isset($post['url']) && is_string($post['url']) ? trim($post['url']) : '';
        if ($id<=0 || $title==='' || $body==='' || strlen($title)>280
            || strlen($body)>5000 || $date===false || $date<=0
            || ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL)
                || stripos($url,'https://')!==0 || strlen($url)>512))) {
            throw new RuntimeException('Invalid PHP7 news post fields.');
        }
        $row = '1:'.$id
            .';2:'.mucho7_news_b64(gmdate('F j, Y',$date))
            .';3:'.mucho7_news_b64($title)
            .';4:0,'.mucho7_news_b64($body);
        if ($url!=='') {
            $row .= '~2,'.mucho7_news_b64('Read more').','.mucho7_news_b64($url);
        }
        $rows[]=$row;
    }
    return '4,'.count($rows).($rows ? '|'.implode('|',$rows) : '');
}

function mucho7_news_response($path,$method,$options)
{
    if (strtoupper($method)!=='GET' &&
        $path!=='/getcustomcontenturl') {
        return '-1';
    }
    if (empty($options['enable_native_news'])) {
        return '-1';
    }
    if ($path==='/getcustomcontenturl') {
        $site = isset($options['site_url']) ? (string)$options['site_url'] : '';
        if (!preg_match('~^https://[A-Za-z0-9.-]+$~D',$site)) {
            return '-1';
        }
        return $site.'/gd-content';
    }
    $sql=isset($options['news_pdo']) && $options['news_pdo'] instanceof PDO;
    $service=$sql?new \MuchoCore\News\NewsService($options['news_pdo']):null;
    $news = $sql?null:mucho7_news_source(isset($options['news_file']) ? $options['news_file'] : '');
    if ($path==='/news/news_version.txt' ||
        $path==='/gd-content/news/news_version.txt') {
        return $sql?$service->nativeVersion():(string)$news['version'];
    }
    if ($path==='/news/news.dat' || $path==='/gd-content/news/news.dat') {
        $raw = mucho7_news_payload($sql?$service->published():$news['posts']);
        $gzip = gzencode($raw,9,ZLIB_ENCODING_GZIP);
        if ($gzip===false) { return '-1'; }
        return mucho7_news_b64($gzip);
    }
    return '-1';
}
