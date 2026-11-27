<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;
use ZipArchive;

/** Legacy Android versions with an audited executable layout. */
final class LegacyAndroidBuild
{
    private const BUILDS = [
        '0a57cba5d2c0ededba194a4770833302a32ea1ea8ad4606ebe1577baa5e73b27' => ['1.0', 2, 7],
        '29028adb6a666ad719d62ad33e3376b93618b95f8e37d96bbd334a07b779eeca' => ['1.1', 5, 10],
        '53f8d97c16535ba63ddc3d1cab9fbb8b1d5e074a30dab5f0ee84ae31ac92a488' => ['1.20', 7, 10],
        'b622a4ce6b50a71d634ea0ea2cf19df662a7d70c4c49fda0260c072f2771dbf3' => ['1.30', 10, 13],
        '98cbb7d1fb02ed436f9742c5591283fc1437b93ce74172b61147015a7636f9bf' => ['1.40', 11, 14],
        '9cc6beebea8c09765cb3ef0e806ecd9f1fe78038fbf18509c69cf20f6ef5f31c' => ['1.50', 13, 16],
        'a34d79fbecb1d2f6b28137f3275a36f793b9e1267ef83a315004a68207296455' => ['1.60', 16, 17],
        '36bf68c8c6a81a4d00c5fff058b28ab4951f40912dbd0d8a21131637da0e3c6c' => ['1.70', 17, 21],
        'f2a9cb18ec5a945324acb511b202a5154bad977a392fe3d0a0e5c3cad74d6a60' => ['1.81', 20, 19],
    ];

    public static function versions(): array
    {
        return array_column(self::BUILDS, 0);
    }

    public static function detect(ZipArchive $source): ?array
    {
        // The known early builds always include armeabi and no ARM64 library.
        if ($source->locateName('lib/armeabi/libgame.so') === false &&
            $source->locateName('lib/armeabi/libcocos2dcpp.so') === false) {
            return null;
        }

        $executables = [];
        $seen = [];
        for ($index = 0; $index < $source->numFiles; $index++) {
            $entry = $source->statIndex($index);
            if (!is_array($entry) || isset($seen[$entry['name']])) {
                throw new RuntimeException('Invalid APK directory or duplicate entry.');
            }
            $name = $entry['name'];
            $seen[$name] = true;
            if ($name === 'AndroidManifest.xml' || $name === 'classes.dex' ||
                (str_starts_with($name, 'lib/') && str_ends_with($name, '.so'))) {
                if ($entry['size'] > 32 * 1024 * 1024) {
                    return null;
                }
                $executables[] = $name;
            }
        }
        if (!isset($seen['AndroidManifest.xml'], $seen['classes.dex']) || isset($seen['classes2.dex'])) {
            return null;
        }

        sort($executables, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($executables as $name) {
            $stream = $source->getStream($name);
            if ($stream === false) {
                throw new RuntimeException('Cannot read executable entry from APK.');
            }
            try {
                hash_update($hash, $name . "\0");
                hash_update_stream($hash, $stream);
            } finally {
                fclose($stream);
            }
        }

        $build = self::BUILDS[hash_final($hash)] ?? null;
        if ($build === null) {
            return null;
        }

        [$version, $number, $endpoints] = $build;
        return [
            'version' => $version,
            'build' => $number,
            'source_build' => "GD $version build $number",
            'x86' => isset($seen['lib/x86/libgame.so']) || isset($seen['lib/x86/libcocos2dcpp.so']),
            'endpoints_per_abi' => $endpoints,
        ];
    }

    /** Replace only complete NUL-terminated endpoint strings. */
    public static function patchNative(string $bytes, string $server, int $expectedCount): array
    {
        $result = preg_replace_callback(
            '~http://www\.boomlings\.com/database/([A-Za-z0-9/]+\.php)\x00~',
            static function (array $match) use ($server): string {
                $url = $server . '/database/' . $match[1];
                if (strlen($url) >= strlen($match[0])) {
                    throw new RuntimeException(
                        'This version needs a shorter server URL. Use a domain or subdomain with a shorter name.'
                    );
                }
                return str_pad($url, strlen($match[0]), "\0");
            },
            $bytes,
            -1,
            $count
        );
        if (!is_string($result) || $count !== $expectedCount) {
            throw new RuntimeException('Legacy endpoint count did not match the original build.');
        }
        return [$result, $count];
    }

    public static function writeWindowsBundle(
        string $apk,
        string $destination,
        array $build,
        string $launcherDirectory
    ): void {
        $archive = new ZipArchive();
        if ($archive->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Cannot create Windows download.');
        }

        try {
            $apkName = 'GeometryDash-MuchoCore.apk';
            if (!$archive->addFile($apk, $apkName) ||
                !$archive->setCompressionName($apkName, ZipArchive::CM_STORE)) {
                throw new RuntimeException('Cannot add the patched APK to the Windows download.');
            }

            foreach (['Start-GD.cmd', 'Start-GD.ps1', 'README.txt'] as $filename) {
                if (!$archive->addFile($launcherDirectory . '/' . $filename, $filename)) {
                    throw new RuntimeException('Windows launcher file is unavailable.');
                }
            }
            $details = $build['source_build'] . "\r\n" .
                ($build['x86'] ? "Native x86 library included." : "ARM only. Requires ARM translation in the emulator.");
            if (!$archive->addFromString('Build.txt', $details . "\r\n")) {
                throw new RuntimeException('Cannot write Windows download version information.');
            }
        } catch (\Throwable $error) {
            $archive->unchangeAll();
            $archive->close();
            @unlink($destination);
            throw $error;
        }
        if (!$archive->close()) {
            @unlink($destination);
            throw new RuntimeException('Cannot finalize the Windows download.');
        }
    }
}
