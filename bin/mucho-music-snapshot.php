<?php

declare(strict_types=1);

/**
 * Standalone PHP 8 music-file snapshot. SQL and Cloud Save keys are separate.
 * Usage: php bin/mucho-music-snapshot.php create SOURCE NEW_SNAPSHOT_DIR
 *        php bin/mucho-music-snapshot.php verify SNAPSHOT_DIR
 *        php bin/mucho-music-snapshot.php restore SNAPSHOT_DIR NEW_TARGET_DIR
 */
umask(0077);

function musicFail(string $message): never
{
    throw new RuntimeException($message);
}

function musicNames(string $directory): array
{
    if (!is_dir($directory) || is_link($directory)) {
        musicFail('Expected a real directory: ' . $directory);
    }
    $names = [];
    foreach (scandir($directory) ?: musicFail('Cannot list directory.') as $name) {
        if ($name === '.' || $name === '..' || $name === '.gitkeep') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (!preg_match('/\A[a-f0-9]{40}\.mp3\z/iD', $name)
            || is_link($path) || !is_file($path)) {
            musicFail('Unexpected entry in music directory: ' . $name);
        }
        $names[] = $name;
    }
    sort($names, SORT_STRING);
    return $names;
}

function musicEmptyTemp(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            musicEmptyTemp($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function musicNewTemp(string $target): string
{
    $parent = dirname($target);
    if (!is_dir($parent) || is_link($parent)) {
        musicFail('Destination parent must be an existing real directory.');
    }
    if (file_exists($target) || is_link($target)) {
        musicFail('Refusing to overwrite an existing destination.');
    }
    $temp = $parent . '/.mucho-music-' . bin2hex(random_bytes(12));
    if (!mkdir($temp, 0700)) {
        musicFail('Cannot create temporary snapshot directory.');
    }
    return $temp;
}

function musicCopyChecked(string $from, string $to): array
{
    if (is_link($from) || !is_file($from) || !is_readable($from)) {
        musicFail('Music file is missing, linked, or unreadable.');
    }
    if (!copy($from, $to) || !chmod($to, 0600)) {
        musicFail('Cannot copy music file.');
    }
    $size = filesize($to);
    $digest = hash_file('sha256', $to);
    if ($size === false || !is_string($digest)
        || $size !== filesize($from)
        || !hash_equals($digest, (string)hash_file('sha256', $from))) {
        musicFail('Music file changed or was corrupted during copy.');
    }
    return ['size' => $size, 'sha256' => $digest];
}

function musicCreate(string $source, string $target): void
{
    $names = musicNames($source);
    $temp = musicNewTemp($target);
    try {
        if (!mkdir($temp . '/files', 0700)) {
            musicFail('Cannot create snapshot files directory.');
        }
        $entries = [];
        foreach ($names as $name) {
            $details = musicCopyChecked($source . '/' . $name, $temp . '/files/' . $name);
            $entries[] = ['name' => $name] + $details;
        }
        $manifest = json_encode(['format' => 'muchocore-music-snapshot-v1',
            'files' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temp . '/manifest.json', $manifest . "\n", LOCK_EX) === false
            || !chmod($temp . '/manifest.json', 0600)) {
            musicFail('Cannot write snapshot manifest.');
        }
        $digest = hash_file('sha256', $temp . '/manifest.json');
        if (!is_string($digest) || file_put_contents($temp . '/manifest.sha256',
            $digest . "  manifest.json\n", LOCK_EX) === false
            || !chmod($temp . '/manifest.sha256', 0600)) {
            musicFail('Cannot write manifest digest.');
        }
        musicVerify($temp);
        if (file_exists($target) || is_link($target) || !rename($temp, $target)) {
            musicFail('Cannot publish snapshot without overwriting a destination.');
        }
    } finally {
        musicEmptyTemp($temp);
    }
}

function musicVerify(string $snapshot): array
{
    if (!is_dir($snapshot) || is_link($snapshot) || !is_dir($snapshot . '/files')
        || is_link($snapshot . '/files')) {
        musicFail('Snapshot directory is invalid.');
    }
    foreach (['manifest.json', 'manifest.sha256'] as $name) {
        if (is_link($snapshot . '/' . $name) || !is_file($snapshot . '/' . $name)) {
            musicFail('Snapshot manifest is missing or unsafe.');
        }
    }
    $sidecar = file_get_contents($snapshot . '/manifest.sha256');
    $actual = hash_file('sha256', $snapshot . '/manifest.json');
    if (!is_string($sidecar) || !is_string($actual)
        || !hash_equals($actual . '  manifest.json' . "\n", $sidecar)) {
        musicFail('Snapshot manifest checksum mismatch.');
    }
    $raw = file_get_contents($snapshot . '/manifest.json');
    if (!is_string($raw) || strlen($raw) > 16 * 1024 * 1024) {
        musicFail('Snapshot manifest is unreadable or too large.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['format'] ?? null) !== 'muchocore-music-snapshot-v1'
        || !isset($data['files']) || !is_array($data['files']) || !array_is_list($data['files'])) {
        musicFail('Unexpected snapshot manifest format.');
    }
    $expected = [];
    foreach ($data['files'] as $entry) {
        if (!is_array($entry) || !isset($entry['name'], $entry['size'], $entry['sha256'])
            || !is_string($entry['name'])
            || !preg_match('/\A[a-f0-9]{40}\.mp3\z/iD', $entry['name'])
            || !is_int($entry['size']) || $entry['size'] < 0
            || !is_string($entry['sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])
            || isset($expected[$entry['name']])) {
            musicFail('Unsafe or duplicate file entry in manifest.');
        }
        $expected[$entry['name']] = $entry;
    }
    $names = musicNames($snapshot . '/files');
    $listed = array_keys($expected);
    sort($listed, SORT_STRING);
    if ($names !== $listed) {
        musicFail('Snapshot file list differs from manifest.');
    }
    foreach ($expected as $name => $entry) {
        $file = $snapshot . '/files/' . $name;
        $size = filesize($file);
        $hash = hash_file('sha256', $file);
        if ($size === false || $size !== $entry['size']
            || !is_string($hash) || !hash_equals($entry['sha256'], $hash)) {
            musicFail('Snapshot file failed checksum verification: ' . $name);
        }
    }
    foreach (scandir($snapshot) ?: [] as $entry) {
        if (!in_array($entry, ['.', '..', 'files', 'manifest.json', 'manifest.sha256'], true)) {
            musicFail('Unexpected entry inside snapshot directory.');
        }
    }
    return $data['files'];
}

function musicRestore(string $snapshot, string $target): void
{
    $entries = musicVerify($snapshot);
    $temp = musicNewTemp($target);
    try {
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $copied = musicCopyChecked($snapshot . '/files/' . $name, $temp . '/' . $name);
            if ($copied['size'] !== $entry['size']
                || !hash_equals($entry['sha256'], $copied['sha256'])) {
                musicFail('Restored file differs from manifest.');
            }
        }
        if (file_exists($target) || is_link($target) || !rename($temp, $target)) {
            musicFail('Cannot restore without overwriting an existing destination.');
        }
    } finally {
        musicEmptyTemp($temp);
    }
}

try {
    $action = $argv[1] ?? '';
    if ($action === 'create' && $argc === 4) {
        musicCreate($argv[2], $argv[3]);
        echo "MUCHO_MUSIC_SNAPSHOT_CREATED\n";
    } elseif ($action === 'verify' && $argc === 3) {
        $entries = musicVerify($argv[2]);
        echo 'MUCHO_MUSIC_SNAPSHOT_VERIFIED files=' . count($entries) . "\n";
    } elseif ($action === 'restore' && $argc === 4) {
        musicRestore($argv[2], $argv[3]);
        echo "MUCHO_MUSIC_SNAPSHOT_RESTORED\n";
    } else {
        musicFail('Usage: create SOURCE NEW_SNAPSHOT_DIR | verify SNAPSHOT_DIR | restore SNAPSHOT_DIR NEW_TARGET_DIR');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'MUCHO_MUSIC_SNAPSHOT_ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
