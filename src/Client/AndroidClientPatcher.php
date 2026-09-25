<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;
use ZipArchive;

final class AndroidClientPatcher
{
    private const SEGMENTS = [
        'a',
        'api',
        'database',
        'accounts',
    ];

    private const KNOWN_HOSTS = [
        'www.boomlings.com',
        'boomlings.com',
        'c92935bj.beget.tech',
        'www.gdserver.net',
        'gdserver.net',
    ];

    public static function patchFile(
        string $inputPath,
        string $outputPath,
        string $serverUrl
    ): array {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'PHP ZipArchive is required for the Android APK patcher.'
            );
        }

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Uploaded APK cannot be read.');
        }

        $size = filesize($inputPath);
        if ($size === false || $size < 1024) {
            throw new RuntimeException(
                'Uploaded file is too small to be a valid Android APK.'
            );
        }

        if ($outputPath === $inputPath) {
            throw new RuntimeException('Output path must be different from input path.');
        }

        $source = new ZipArchive();
        $openResult = $source->open($inputPath);
        if ($openResult !== true) {
            throw new RuntimeException('The uploaded file is not a readable APK/ZIP archive.');
        }

        $hasManifest = false;
        $replacements = self::buildReplacements($server);
        $replacementCount = 0;
        $patchedEntries = [];

        $out = new ZipArchive();
        if ($out->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            throw new RuntimeException('Cannot create the patched APK output file.');
        }

        for ($index = 0; $index < $source->numFiles; $index++) {
            $stat = $source->statIndex($index);
            $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';

            if ($name === '') {
                continue;
            }

            if ($name === 'AndroidManifest.xml') {
                $hasManifest = true;
            }

            if (self::isSignatureEntry($name)) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                $out->addEmptyDir($name);
                continue;
            }

            $data = $source->getFromIndex($index);
            if ($data === false) {
                $out->close();
                $source->close();
                @unlink($outputPath);
                throw new RuntimeException('Failed to read APK entry: ' . $name);
            }

            $original = $data;

            if (self::shouldPatchEntry($name)) {
                $count = 0;
                $data = self::replaceBuffer($data, $replacements, $count);
                if ($count > 0) {
                    $replacementCount += $count;
                    $patchedEntries[] = [
                        'name' => $name,
                        'replacements' => $count,
                    ];
                }
            }

            if ($out->addFromString($name, $data) === false) {
                $out->close();
                $source->close();
                @unlink($outputPath);
                throw new RuntimeException('Failed to write APK entry: ' . $name);
            }

            $method = (int)($stat['comp_method'] ?? -1);
            if (in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                $out->setCompressionName($name, $method);
            }

            unset($original, $data);
        }

        $source->close();

        if (!$hasManifest) {
            $out->close();
            @unlink($outputPath);
            throw new RuntimeException('The uploaded archive does not contain AndroidManifest.xml.');
        }

        if ($replacementCount <= 0) {
            $out->close();
            @unlink($outputPath);
            throw new RuntimeException(
                'No supported Geometry Dash server URL was found in the APK.'
            );
        }

        if (!$out->close()) {
            @unlink($outputPath);
            throw new RuntimeException('Failed to finalize the patched APK.');
        }

        $outputSize = filesize($outputPath);
        if ($outputSize === false || $outputSize < 1024) {
            @unlink($outputPath);
            throw new RuntimeException('Patched APK output is invalid.');
        }

        $verify = new ZipArchive();
        if ($verify->open($outputPath) !== true) {
            @unlink($outputPath);
            throw new RuntimeException('Patched APK could not be reopened after creation.');
        }

        $verify->close();

        return [
            'server_url' => $server,
            'input_size' => $size,
            'output_size' => $outputSize,
            'replacement_count' => $replacementCount,
            'patched_entries' => $patchedEntries,
            'input_sha256' => hash_file('sha256', $inputPath) ?: '',
            'output_sha256' => hash_file('sha256', $outputPath) ?: '',
            'signed' => false,
        ];
    }

    private static function shouldPatchEntry(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'lib/')
            || str_starts_with($lower, 'assets/')
            || preg_match('~(^|/)classes(?:[0-9]+)?\.dex$~', $lower) === 1
            || preg_match('~\.(json|xml|txt|ini|cfg|dat)$~', $lower) === 1;
    }

    private static function isSignatureEntry(string $name): bool
    {
        $upper = strtoupper($name);

        return str_starts_with($upper, 'META-INF/')
            && (
                $upper === 'META-INF/MANIFEST.MF'
                || str_ends_with($upper, '.SF')
                || str_ends_with($upper, '.RSA')
                || str_ends_with($upper, '.DSA')
                || str_ends_with($upper, '.EC')
            );
    }

    /**
     * @return array<string,string>
     */
    private static function buildReplacements(string $server): array
    {
        $parsed = parse_url($server);
        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid server URL.');
        }

        $base = (string)$parsed['scheme'] . '://' .
            (string)$parsed['host'] .
            (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');

        $forms = [
            'https://www.boomlings.com/database' => 34,
            'http://www.boomlings.com/database' => 33,
            'https://www.boomlings.com/' => 26,
            'http://www.boomlings.com/' => 25,
            'www.boomlings.com/database' => 26,
        ];

        foreach (self::KNOWN_HOSTS as $host) {
            if ($host === 'www.boomlings.com') {
                continue;
            }

            $forms['https://' . $host . '/database'] = null;
            $forms['http://' . $host . '/database'] = null;
        }

        $map = [];

        foreach ($forms as $old => $fixedLength) {
            $length = $fixedLength ?? strlen($old);
            $new = self::compatibleUrl($base, $length, false);

            if ($new === null || strlen($old) !== strlen($new)) {
                continue;
            }

            $map[$old] = $new;
            $map[base64_encode($old)] = base64_encode($new);
            $map[self::asciiToUtf16Le($old)] = self::asciiToUtf16Le($new);
        }

        $bareNew = self::compatibleUrl($base, 26, true);
        if ($bareNew !== null) {
            $old = 'www.boomlings.com/database';
            $map[$old] = $bareNew;
            $map[base64_encode($old)] = base64_encode($bareNew);
        }

        if ($map === []) {
            throw new RuntimeException(
                'The supplied server URL is too long for the supported APK URL layouts.'
            );
        }

        return $map;
    }

    private static function asciiToUtf16Le(string $value): string
    {
        $result = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $result .= $value[$i] . "\x00";
        }

        return $result;
    }

    private static function compatibleUrl(
        string $server,
        int $desiredLength,
        bool $bare
    ): ?string {
        $parsed = parse_url($server);
        if (!is_array($parsed)) {
            return null;
        }

        $scheme = (string)$parsed['scheme'];
        $netloc = (string)$parsed['host'] .
            (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');

        $queue = [[]];
        $seen = [''];

        while ($queue !== []) {
            $parts = array_shift($queue);
            $suffix = $parts === [] ? '' : '/' . implode('/', $parts);

            $candidate = $bare
                ? $netloc . $suffix . '/database'
                : $scheme . '://' . $netloc . $suffix;

            if (strlen($candidate) === $desiredLength) {
                return $candidate;
            }

            if (count($parts) >= 6) {
                continue;
            }

            foreach (self::SEGMENTS as $segment) {
                if ($bare && $segment === 'database') {
                    continue;
                }

                $next = $parts;
                $next[] = $segment;
                $key = implode('/', $next);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = $next;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $replacements
     */
    private static function replaceBuffer(
        string $buffer,
        array $replacements,
        int &$count
    ): string {
        $count = 0;

        foreach ($replacements as $old => $new) {
            $matches = substr_count($buffer, $old);
            if ($matches > 0) {
                $buffer = str_replace($old, $new, $buffer);
                $count += $matches;
            }
        }

        return $buffer;
    }
}
