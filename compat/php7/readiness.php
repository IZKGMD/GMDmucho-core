<?php
/**
 * Owner-only PHP 7.0 shared-hosting preflight. Read-only by design.
 *
 * This is a CONFIGURATION diagnostic, not a real-client compatibility or
 * security certification. No private values or save payloads are rendered.
 */
declare(strict_types=1);

function mucho7_readiness_ini_bytes($value)
{
    $value = trim((string)$value);
    if ($value === '-1') return -1;
    if (!preg_match('/\A([0-9]+)\s*([KMG]?)\z/iD', $value, $match)) return null;
    $multiplier = array(''=>1, 'K'=>1024, 'M'=>1048576, 'G'=>1073741824);
    $number = (float)$match[1] * $multiplier[strtoupper($match[2])];
    if (!is_finite($number) || $number > PHP_INT_MAX) return PHP_INT_MAX;
    return (int)$number;
}

function mucho7_readiness_key_status($path)
{
    if (is_link($path)) return 'invalid';
    if (!is_file($path)) return 'missing';
    if (!is_readable($path)) return 'unreadable';
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return 'unreadable';
    $encoded = trim($raw);
    $key = preg_match('/\A[a-f0-9]{64}\z/iD', $encoded) === 1
        ? hex2bin($encoded) : base64_decode($encoded, true);
    return is_string($key) && strlen($key) === 32 ? 'valid' : 'invalid';
}

function mucho7_readiness_installed_hash($path)
{
    if (!is_file($path) || is_link($path)) return '';
    $raw = @file_get_contents($path);
    $data = is_string($raw) && strlen($raw) < 8192 ? json_decode($raw, true) : null;
    return is_array($data) && isset($data['sha256']) &&
        is_string($data['sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $data['sha256']) === 1
        ? $data['sha256'] : '';
}

/** Collect only non-secret runtime facts. Never read private key content into UI. */
function mucho7_readiness_snapshot($root, $settings)
{
    $settings = is_array($settings) ? $settings : array();
    $config = $root.'/config';
    $url = isset($settings['site_url']) && is_string($settings['site_url'])
        ? rtrim($settings['site_url'], '/') : '';
    $parts = $url !== '' ? parse_url($url) : false;
    $siteValid = is_array($parts) && isset($parts['scheme'], $parts['host']) &&
        $parts['scheme'] === 'https' &&
        preg_match('/\A[A-Za-z0-9.-]+\z/D', $parts['host']) === 1 &&
        !isset($parts['user']) && !isset($parts['pass']) &&
        !isset($parts['port']) && !isset($parts['query']) &&
        !isset($parts['fragment']) &&
        (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/');
    return array(
        'https' => function_exists('mucho7_https') ? mucho7_https() : false,
        'site_valid' => $siteValid,
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'openssl' => extension_loaded('openssl'),
        'zlib' => extension_loaded('zlib'),
        'mbstring' => extension_loaded('mbstring'),
        'post_limit' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'config_writable' => is_dir($config) && is_writable($config) && !is_link($config),
        'core_active' => is_file($config.'/php7-complete.on'),
        'core_transition' => is_file($config.'/php7-core-transition.json'),
        'core_hash' => mucho7_readiness_installed_hash($config.'/php7-core-build.json'),
        'available_hash' => mucho7_readiness_installed_hash($config.'/php7-core-update.json'),
        'key_status' => mucho7_readiness_key_status($config.'/cloudsave.key'),
        'accounts' => !empty($settings['enable_accounts']),
        'writes' => !empty($settings['enable_writes']),
        'cloudsave' => !empty($settings['enable_cloudsave']),
        'registration' => !empty($settings['enable_registration']) &&
            !is_file($config.'/registrations-disabled.flag'),
        'php_version' => PHP_VERSION
    );
}

/** Pure classification to make error behavior testable without a database. */
function mucho7_readiness_evaluate($facts)
{
    $checks = array();
    $add = function ($status, $title, $detail) use (&$checks) {
        $checks[] = array('status'=>$status, 'title'=>$title, 'detail'=>$detail);
    };
    $add(!empty($facts['https']) && !empty($facts['site_valid']) ? 'ok':'error',
        'HTTPS origin', 'Use your public HTTPS domain root, without /mc or /database.');
    $add(!empty($facts['pdo_mysql']) && !empty($facts['openssl']) && !empty($facts['zlib']) ? 'ok':'error',
        'Core extensions', 'pdo_mysql, openssl and zlib are required.');
    $add(!empty($facts['mbstring']) ? 'ok':'warn',
        'International text and passkeys', 'Enable mbstring for complete administrator/account features.');
    $post = mucho7_readiness_ini_bytes(isset($facts['post_limit']) ? $facts['post_limit'] : '');
    // post_max_size=0 means unlimited in PHP's ordinary form processing.
    $add($post === 0 || $post === -1 || ($post !== null && $post >= 26*1048576) ? 'ok':'error',
        'Save POST allowance', 'Use post_max_size >= 26M for URL-encoded 8 MiB saves; the host may impose a lower hard limit.');
    $memory = mucho7_readiness_ini_bytes(isset($facts['memory_limit']) ? $facts['memory_limit'] : '');
    $add($memory === -1 || ($memory !== null && $memory >= 128*1048576) ? 'ok':'error',
        'PHP memory', 'Use memory_limit >= 128M for the native build and save handling.');
    $add(!empty($facts['config_writable']) ? 'ok':'error',
        'Private configuration', 'The PHP worker must be able to write private locks, key and backup metadata in mc/config.');
    $active = !empty($facts['core_active']) && empty($facts['core_transition']);
    $add($active ? 'ok':'error',
        'Full game core', !empty($facts['core_transition'])
            ? 'A migration is in progress. Resume it from Server; do not reinstall.'
            : 'Complete the owner migration from Server before inviting players.');
    if (!empty($facts['available_hash']) && $facts['available_hash'] !== (isset($facts['core_hash'])?$facts['core_hash']:'')) {
        $add('warn','Uploaded build pending','Install uploaded build from Server, then run the game-client checks again.');
    }
    $key = isset($facts['key_status']) ? $facts['key_status'] : 'missing';
    $add($key === 'valid' ? 'ok' : ($key === 'missing' ? 'warn':'error'),
        'Cloud Save encryption key',
        $key === 'valid' ? 'Existing private key is readable and correctly encoded; never rotate it.'
        : ($key === 'missing'
            ? 'Cloud Save key is absent. A first backup can initialize it only if there are no encrypted saves; check the server before testing.'
            : 'Cloud Save key is unreadable or invalid. Restore the ORIGINAL key from backup; never generate a replacement over encrypted saves.'));
    $enabled = !empty($facts['accounts']) && !empty($facts['writes']) && !empty($facts['cloudsave']);
    $add($enabled?'ok':'warn', 'Game write switches',
        'Enable accounts, writes and Cloud Save in the owner settings if this GDPS should accept player backups.');
    $add(!empty($facts['registration'])?'ok':'warn', 'Player registration',
        'Registration can be intentionally disabled independently of login and Cloud Save.');
    return $checks;
}

function mucho7_readiness_render($root)
{
    // This function must be called ONLY from the authenticated owner Server
    // view. Never expose even feature switch state on a public endpoint.
    $settings = mucho7_load_settings($root.'/config/php7-local.php');
    $checks = mucho7_readiness_evaluate(mucho7_readiness_snapshot($root,$settings));
    $errors = 0;
    foreach ($checks as $check) if ($check['status']==='error') $errors++;
    echo '<section class="card" aria-labelledby="mcHostingPreflightTitle">';
    echo '<h2 id="mcHostingPreflightTitle">5v.pl hosting preflight</h2>';
    echo '<p class="muted">Read-only checks on this PHP worker. They do not prove that the actual Geometry Dash 2.209 client works.</p>';
    echo '<p role="status"><strong>'.($errors ? $errors.' hosting issue(s) need attention' : 'No blocking local hosting issues detected').'</strong></p>';
    echo '<ul class="mc-readiness-list" style="list-style:none;padding:0;margin:14px 0;display:grid;gap:11px">';
    foreach ($checks as $check) {
        $word = $check['status']==='ok'?'PASS':($check['status']==='warn'?'CHECK':'FIX');
        $label = htmlspecialchars($word.' — '.$check['title'],ENT_QUOTES,'UTF-8');
        $detail = htmlspecialchars($check['detail'],ENT_QUOTES,'UTF-8');
        echo '<li style="border-top:1px solid var(--mc-line,var(--border,#303345));padding-top:9px">'
            .'<strong>'.$label.'</strong><p class="muted" style="margin:5px 0 0">'.$detail.'</p></li>';
    }
    echo '</ul><p class="muted">After these checks, test real client registration, login, level search/upload, Cloud Save Save and Load, comments and messages. Keep a separate database + cloudsave.key backup.</p>';
    echo '</section>';
}
