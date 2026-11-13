<?php

declare(strict_types=1);

/**
 * Exercise the real single-file FTP bootstrap copier in a private temp tree.
 * The published installer must remain self-contained, so extract only its
 * copy/rollback functions; no network, session, or live installation is run.
 */
$installer = (string)file_get_contents(dirname(__DIR__, 2) . '/public/ftp-install.php');
$start = strpos($installer, 'function copy_tree(');
$end = strpos($installer, "\nfunction page(", $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('FTP installer copy/rollback functions are missing.');
}
function fail_install(string $message): never
{
    throw new RuntimeException($message);
}
eval(substr($installer, $start, $end - $start));

$check = static function (bool $ok, string $description): void {
    if (!$ok) {
        throw new RuntimeException('FAIL FTP file preservation: ' . $description);
    }
    echo 'PASS ' . $description . PHP_EOL;
};

$root = sys_get_temp_dir() . '/mc-ftp-rollback-' . bin2hex(random_bytes(8));
$source = $root . '/source';
$target = $root . '/target';
mkdir($source . '/docs', 0700, true);
mkdir($target . '/docs', 0700, true);
file_put_contents($source . '/docs/guide.txt', 'new-release');
file_put_contents($target . '/docs/guide.txt', 'pre-existing-important-document');
file_put_contents($target . '/keep.txt', 'unrelated-hosting-data');

try {
    $created = [];
    $rejected = false;
    try {
        copy_tree($source, $target, $created);
    } catch (RuntimeException) {
        $rejected = true;
    }
    rollback($created);
    $check($rejected &&
        file_get_contents($target . '/docs/guide.txt') === 'pre-existing-important-document' &&
        is_dir($target . '/docs') &&
        file_get_contents($target . '/keep.txt') === 'unrelated-hosting-data',
        'conflicting existing file remains byte-for-byte intact after rollback');

    // A fresh destination installs successfully and rollback affects only
    // files/directories created by this operation.
    unlink($target . '/docs/guide.txt');
    $created = [];
    copy_tree($source, $target, $created);
    $check(file_get_contents($target . '/docs/guide.txt') === 'new-release' &&
        count($created) === 1,
        'fresh archive file installs without claiming host-owned directories');
    rollback($created);
    $check(!file_exists($target . '/docs/guide.txt') &&
        is_dir($target . '/docs') && is_file($target . '/keep.txt'),
        'rollback removes only created file and preserves existing directory');

    // A newly created directory may be removed only if it belongs to the
    // installer; symlink entries must never be traversed or installed.
    mkdir($source . '/newdir', 0700);
    file_put_contents($source . '/newdir/file.txt', 'created');
    $created = [];
    copy_tree($source, $target, $created);
    rollback($created);
    $check(!file_exists($target . '/newdir') && is_dir($target . '/docs'),
        'rollback removes newly created directory only');

    if (function_exists('symlink') && @symlink($target . '/keep.txt', $source . '/evil.txt')) {
        $created = [];
        $rejected = false;
        try {
            copy_tree($source, $target, $created);
        } catch (RuntimeException) {
            $rejected = true;
        }
        rollback($created);
        $check($rejected && file_get_contents($target . '/keep.txt') === 'unrelated-hosting-data',
            'archive symbolic link is rejected without touching hosting data');
    }
    echo "MUCHOCORE_FTP_INSTALLER_PRESERVATION_OK\n";
} finally {
    $remove = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($remove as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($root);
}
