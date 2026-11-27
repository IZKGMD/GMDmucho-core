<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Client/DeploymentClientFiles.php';

use MuchoCore\Client\DeploymentClientFiles;

function expectError(callable $work, string $fragment): void
{
    try {
        $work();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $fragment)) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected error: ' . $fragment);
}

$root = sys_get_temp_dir() . '/mucho-required-' . bin2hex(random_bytes(8));
$client = $root . '/storage/client-sources';
$runtime = $client . '/windows-runtime';
$profile = $client . '/required-client-files.json';
$apk = $root . '/fixture.apk';
$bundle = $root . '/client.zip';
$plugin = 'lib/x86/libfixture.so';
try {
    mkdir($runtime . '/Resources', 0700, true);
    mkdir($runtime . '/plugins', 0700, true);
    file_put_contents($runtime . '/Resources/game-sheet.png', 'fixture');
    file_put_contents($runtime . '/libcocos2d.dll', 'fixture');

    $configuration = [
        'version' => 1,
        'windows' => ['libcocos2d.dll', 'Resources/game-sheet.png', 'plugins/missing.dll'],
        'android_plugins' => [$plugin => hash('sha256', 'android plugin')],
    ];
    file_put_contents($profile, json_encode($configuration, JSON_THROW_ON_ERROR));
    expectError(static fn() => DeploymentClientFiles::load($root), 'Required Windows client file is missing');
    file_put_contents($runtime . '/plugins/missing.dll', 'fixture');
    $requirements = DeploymentClientFiles::load($root);

    $zip = new ZipArchive();
    $zip->open($apk, ZipArchive::CREATE);
    $zip->addFromString('classes.dex', 'fixture');
    $zip->close();
    expectError(static fn() => DeploymentClientFiles::verifyApk($apk, $requirements), 'Required Android plugin is missing');
    $zip->open($apk);
    $zip->addFromString($plugin, 'invalid plugin');
    $zip->close();
    expectError(static fn() => DeploymentClientFiles::verifyApk($apk, $requirements), 'Android plugin differs');
    $zip->open($apk);
    $zip->addFromString($plugin, 'android plugin');
    $zip->close();
    DeploymentClientFiles::verifyApk($apk, $requirements);

    $zip->open($bundle, ZipArchive::CREATE);
    DeploymentClientFiles::addWindows(
        $zip, dirname(__DIR__, 2), $apk, $requirements, 'https://gdps.example'
    );
    $zip->close();
    $zip->open($bundle);
    $manifest = json_decode($zip->getFromName('windows/client-verification.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach ($manifest['files'] as $file) {
        $content = $zip->getFromName('windows/' . $file['name']);
        if (!is_string($content) || !hash_equals($file['sha256'], hash('sha256', $content))) {
            throw new RuntimeException('Manifest hash did not match the packaged file.');
        }
    }
    if ($zip->locateName('windows/START-MuchoCore.cmd') === false || count($manifest['files']) !== 4) {
        throw new RuntimeException('Windows bundle is incomplete.');
    }
    $zip->close();

    $configuration['windows'][] = '../outside.dll';
    file_put_contents($profile, json_encode($configuration, JSON_THROW_ON_ERROR));
    expectError(static fn() => DeploymentClientFiles::load($root), 'reserved path');
    echo "Client pack rejects missing or modified plugins and verifies the Windows manifest.\n";
} finally {
    if (is_dir($root)) {
        $paths = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($paths as $path) {
            if ($path->isDir()) rmdir($path->getPathname());
            else unlink($path->getPathname());
        }
        rmdir($root);
    }
}
