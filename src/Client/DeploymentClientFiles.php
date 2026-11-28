<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;
use ZipArchive;

/** Owner-supplied files that must be present before a client pack can be published. */
final class DeploymentClientFiles
{
    public static function load(string $root): ?array
    {
        $directory = $root . '/storage/client-sources';
        $profile = $directory . '/required-client-files.json';
        if (!is_file($profile)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($profile), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new RuntimeException('Unsupported required-client-files.json version.');
        }
        $windows = $data['windows'] ?? null;
        $android = $data['android_plugins'] ?? null;
        if (!is_array($windows) || !array_is_list($windows) || !is_array($android) || $android === []) {
            throw new RuntimeException('Declare Windows runtime files and required Android plugin entries.');
        }

        $runtime = realpath($directory . '/windows-runtime');
        if ($runtime === false || !is_dir($runtime)) {
            throw new RuntimeException('The original Windows runtime directory is missing.');
        }
        $files = [];
        $names = [];
        $resources = false;
        $plugin = false;
        foreach ($windows as $name) {
            self::validatePath($name);
            $key = strtolower($name);
            if (isset($names[$key]) || in_array($key, self::reservedWindowsNames(), true)) {
                throw new RuntimeException('Duplicate or reserved Windows file in client profile: ' . $name);
            }
            $names[$key] = true;
            $path = realpath($runtime . '/' . $name);
            if ($path === false || !str_starts_with($path, $runtime . DIRECTORY_SEPARATOR) ||
                !is_readable($path) || !is_file($path)) {
                throw new RuntimeException('Required Windows client file is missing: ' . $name);
            }
            $files[$name] = ['path' => $path, 'name' => $name] + self::fingerprint($path);
            $resources = $resources || str_starts_with($key, 'resources/');
            $plugin = $plugin || preg_match('~^(?:plugins|geode/mods)/.+\.(?:dll|geode)$~D', $key) === 1;
        }
        if (!$resources || !$plugin) {
            throw new RuntimeException('The Windows file set must include Resources and at least one declared plugin.');
        }

        foreach ($android as $name => $sha256) {
            self::validatePath($name);
            if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
                throw new RuntimeException('Every required APK entry needs an expected SHA-256: ' . $name);
            }
        }

        $builder = $data['android_builder'] ?? 'standard';
        if (!in_array($builder, ['standard', 'legacy-cocos-x86'], true)) {
            throw new RuntimeException('The requested Android plugin builder is unsupported.');
        }
        $library = null;
        if ($builder === 'legacy-cocos-x86') {
            $library = $directory . '/android/libmuchocore_songs.so';
            $expected = $android['lib/x86/libmuchocore_songs.so'] ?? '';
            if (!is_readable($library) || !is_file($library) || hash_file('sha256', $library) !== $expected) {
                throw new RuntimeException('The configured Cocos2d x86 plugin is missing or has the wrong SHA-256.');
            }
            if (!is_file($root . '/patchers/android/legacy-custom-songs/patch_apk.py')) {
                throw new RuntimeException('The Cocos2d APK patcher is not installed.');
            }
        }
        return [
            'windows' => $files,
            'android_plugins' => $android,
            'cocos_library' => $library,
            'fingerprint' => hash('sha256', json_encode([$files, $android, $builder], JSON_THROW_ON_ERROR)),
        ];
    }

    public static function patchAndroid(
        string $root,
        string $input,
        string $output,
        string $server,
        ?array $requirements,
        ?callable $heartbeat,
        bool $installAlongside = false,
        string $expectedVersion = 'auto'
    ): array {
        if (!is_string($requirements['cocos_library'] ?? null)) {
            return AndroidClientPatcher::patchFile($input, $output, $server, $heartbeat, $installAlongside, $expectedVersion);
        }
        if (!in_array($expectedVersion, ['auto', '1.81'], true)) {
            throw new RuntimeException('This Cocos2d plugin is built for GD 1.81 x86.');
        }

        $tools = $root . '/storage/client-sources/android/tools';
        $process = proc_open([
            'python3', $root . '/patchers/android/legacy-custom-songs/patch_apk.py',
            $input, $output, '--server', $server, '--tools-dir', $tools,
            '--cocos-x86-library', $requirements['cocos_library'],
        ], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the Cocos2d APK builder.');
        }
        fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !is_file($output)) {
            @unlink($output);
            throw new RuntimeException('Cocos2d APK patch failed: ' . trim((string)$error));
        }
        if ($heartbeat !== null) {
            $heartbeat();
        }
        return [
            'server_url' => $server,
            'input_size' => filesize($input),
            'input_sha256' => hash_file('sha256', $input),
            'output_size' => filesize($output),
            'output_sha256' => hash_file('sha256', $output),
            'replacement_count' => 19,
            'patched_entries' => [['name' => 'lib/x86/libcocos2dcpp.so', 'replacements' => 19]],
            'package_id' => 'com.robtopx.geometryjump',
            'source_build' => 'GD 1.81 build 20 x86 with Cocos2d songs',
            'legacy_build' => ['version' => '1.81', 'source_build' => 'GD 1.81 build 20 x86', 'x86' => true],
            'signed' => true,
        ];
    }

    public static function verifyApk(string $apk, array $requirements): void
    {
        $zip = new ZipArchive();
        if ($zip->open($apk) !== true) {
            throw new RuntimeException('Cannot verify the APK plugin files.');
        }
        try {
            foreach ($requirements['android_plugins'] as $name => $sha256) {
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Required Android plugin is missing: ' . $name);
                }
                try {
                    $digest = hash_init('sha256');
                    hash_update_stream($digest, $stream);
                    if (!hash_equals($sha256, hash_final($digest))) {
                        throw new RuntimeException('Android plugin differs from the configured build: ' . $name);
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    public static function addWindows(
        ZipArchive $zip, string $root, string $exe, array $requirements, string $server
    ): array {
        $files = [
            'GeometryDash.exe' => ['path' => $exe, 'name' => 'GeometryDash.exe'] + self::fingerprint($exe),
        ] + $requirements['windows'];
        $manifest = [];
        foreach ($files as $name => $file) {
            self::add($zip, $file['path'], 'windows/' . $name);
            unset($file['path']);
            $manifest[] = $file;
        }
        foreach (['START-MuchoCore.cmd', 'Start-MuchoCore.ps1'] as $launcher) {
            self::add($zip, $root . '/tools/client/' . $launcher, 'windows/' . $launcher);
        }
        if (!$zip->addFromString('windows/client-verification.json', json_encode([
            'version' => 1,
            'server_url' => $server,
            'files' => $manifest,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Cannot add the Windows launch manifest.');
        }
        return $manifest;
    }

    private static function add(ZipArchive $zip, string $source, string $target): void
    {
        if (!is_readable($source) || !$zip->addFile($source, $target)) {
            throw new RuntimeException('Cannot add client pack file: ' . $target);
        }
    }

    private static function reservedWindowsNames(): array
    {
        return ['geometrydash.exe', 'start-muchocore.cmd', 'start-muchocore.ps1', 'client-verification.json'];
    }

    private static function fingerprint(string $path): array
    {
        $bytes = filesize($path);
        $digest = hash_file('sha256', $path);
        if (!$bytes || !is_string($digest)) {
            throw new RuntimeException('Cannot verify client file: ' . basename($path));
        }
        return ['size' => $bytes, 'sha256' => $digest];
    }

    private static function validatePath(mixed $name): void
    {
        if (!is_string($name) || strlen($name) > 240 ||
            !preg_match('~^[A-Za-z0-9_. -]+(?:/[A-Za-z0-9_. -]+)*$~D', $name)) {
            throw new RuntimeException('Client profile contains an invalid relative path.');
        }
        foreach (explode('/', $name) as $segment) {
            if (rtrim($segment, '. ') !== $segment || preg_match(
                '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD', $segment
            )) {
                throw new RuntimeException('Client profile contains a reserved path component.');
            }
        }
    }
}
