<?php

declare(strict_types=1);

/** No existing server, SQL or Cloud Save key is touched. */
$command = dirname(__DIR__, 2) . '/bin/mucho-music-snapshot.php';
$root = sys_get_temp_dir() . '/mc-music-snapshot-test-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Failed to allocate test root');
}

$run = static function (array $arguments) use ($command): array {
    $process = proc_open([PHP_BINARY, $command, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute PHP snapshot command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$check = static function (bool $value, string $label): void {
    if (!$value) {
        throw new RuntimeException('Music snapshot: ' . $label);
    }
    echo 'PASS ' . $label . "\n";
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $file) {
            $remove($file->getPathname());
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

try {
    $source = $root . '/source';
    mkdir($source, 0700);
    $files = [
        str_repeat('a', 40) . '.mp3' => "ID3\0" . random_bytes(512),
        str_repeat('b', 40) . '.mp3' => "MP3 binary\0\xFF\x00" . random_bytes(1000),
    ];
    foreach ($files as $name => $bytes) {
        file_put_contents($source . '/' . $name, $bytes);
    }
    file_put_contents($source . '/.gitkeep', '');
    $snapshot = $root . '/snapshot';
    $check($run(['create', $source, $snapshot])[0] === 0,
        'create snapshot from two uploaded MP3 files');
    [$status, $output] = $run(['verify', $snapshot]);
    $check($status === 0 && str_contains($output, 'files=2'),
        'verify manifest and exact MP3 count');
    $restored = $root . '/restored';
    $check($run(['restore', $snapshot, $restored])[0] === 0,
        'restore into previously nonexistent directory');
    foreach ($files as $name => $bytes) {
        $check(file_get_contents($restored . '/' . $name) === $bytes,
            'restored original MP3 bytes without changes');
    }
    $check($run(['restore', $snapshot, $restored])[0] !== 0,
        'refuse overwrite of existing installation');

    $name = array_key_first($files);
    file_put_contents($snapshot . '/files/' . $name, 'corrupt');
    $check($run(['verify', $snapshot])[0] !== 0,
        'detect corrupt MP3 bytes before restoring');
    $blocked = $root . '/blocked';
    $check($run(['restore', $snapshot, $blocked])[0] !== 0 && !file_exists($blocked),
        'failed verification cannot publish an incomplete restore');

    $clean = $root . '/clean';
    $check($run(['create', $source, $clean])[0] === 0, 'create a fresh snapshot');
    $manifestPath = $clean . '/manifest.json';
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['files'][0]['name'] = '../outside.mp3';
    file_put_contents($manifestPath,
        json_encode($manifest, JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($clean . '/manifest.sha256',
        hash_file('sha256', $manifestPath) . "  manifest.json\n");
    $check($run(['verify', $clean])[0] !== 0,
        'reject path traversal even with a self-consistent manifest checksum');

    if (function_exists('symlink')) {
        symlink($source . '/' . $name, $source . '/' . str_repeat('c', 40) . '.mp3');
        $check($run(['create', $source, $root . '/linked'])[0] !== 0,
            'reject symlinks in music source');
    }
    echo "MUCHOCORE_PHP8_MUSIC_SNAPSHOT_OK\n";
} finally {
    $remove($root);
}
