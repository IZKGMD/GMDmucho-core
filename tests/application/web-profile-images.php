<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$image = (string)file_get_contents($root . '/public/assets/muchocore-logo.jpg');
$version = hash('sha256', $image);
$base = ['account' => '1', 'kind' => 'avatar', 'version' => $version];
$fixtures = [
    ['GET', ['account' => '../1', 'kind' => 'avatar', 'version' => $version], 404, '', []],
    ['GET', ['account' => ['1'], 'kind' => 'avatar', 'version' => $version], 404, '', []],
    ['GET', ['account' => '2147483648', 'kind' => 'avatar', 'version' => $version], 404, '', []],
    ['GET', ['account' => '1', 'kind' => 'anything', 'version' => $version], 404, '', []],
    ['GET', ['account' => '1', 'kind' => 'avatar', 'version' => 'not-a-version'], 404, '', []],
    ['POST', $base, 405, '', []],
];
if (!in_array('--invalid-only', $argv, true)) {
    $fixtures = array_merge($fixtures, [
        ['GET', $base, 200, $image, []],
        ['HEAD', $base, 200, '', []],
        ['GET', $base, 304, '', ['HTTP_IF_NONE_MATCH' => '"' . $version . '"']],
        ['GET', array_merge($base, ['version' => str_repeat('0', 64)]), 404, '', []],
        ['GET', $base, 404, '', ['fixture_banned' => true]],
        ['GET', $base, 404, '', ['fixture_inactive' => true]],
    ]);
}
foreach ($fixtures as $index => [$method, $query, $status, $body, $options]) {
    $fixture = tempnam(sys_get_temp_dir(), 'mucho-web-image-');
    $source = <<<'PHP'
<?php
namespace MuchoCore\Database {
    final class Database {
        public function connection(): \PDO {
            $db = new \PDO('sqlite::memory:');
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE accounts (account_id INTEGER PRIMARY KEY, is_active INTEGER, is_banned INTEGER)');
            $db->exec('CREATE TABLE mucho_profile_media (account_id INTEGER, kind TEXT, version TEXT, image_data BLOB)');
            $db->prepare('INSERT INTO accounts VALUES (1,?,?)')->execute([empty($_SERVER['fixture_inactive']) ? 1 : 0, empty($_SERVER['fixture_banned']) ? 0 : 1]);
            $bytes = file_get_contents($GLOBALS['fixture_root'].'/public/assets/muchocore-logo.jpg');
            $db->prepare('INSERT INTO mucho_profile_media VALUES (1,?,?,?)')->execute(['avatar',hash('sha256',$bytes),$bytes]);
            return $db;
        }
    }
}
namespace {
    http_response_code(200);
    ob_start();
    register_shutdown_function(static function(): void { $bytes=(string)ob_get_clean(); echo json_encode(['status'=>http_response_code(),'body'=>base64_encode($bytes)]); });
PHP;
    $source .= '$GLOBALS["fixture_root"]=' . var_export($root, true) . ';';
    $source .= '$_SERVER=' . var_export(array_merge($options, ['REQUEST_METHOD' => $method]), true) . ';';
    $source .= '$_GET=' . var_export($query, true) . ';';
    $source .= 'require ' . var_export($root . '/public/profile-image.php', true) . ';}';
    file_put_contents($fixture, $source);
    try {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $fixture], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to start image request fixture.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode((string)$output, true);
        if ($exit !== 0 || !is_array($result) || $result['status'] !== $status || base64_decode($result['body']) !== $body) {
            throw new RuntimeException('Image request fixture ' . $index . ' failed: ' . $output . ' ' . $errors);
        }
        echo "PASS web profile image request $index ($method, $status)\n";
    } finally { unlink($fixture); }
}
