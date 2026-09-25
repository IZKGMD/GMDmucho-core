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

        $rebuiltPath = $outputPath . '.rebuilt';
        $alignedPath = $outputPath . '.aligned';

        @unlink($rebuiltPath);
        @unlink($alignedPath);
        @unlink($outputPath);

        $out = new ZipArchive();
        if ($out->open($rebuiltPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            throw new RuntimeException('Cannot create the rebuilt APK output file.');
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
                @unlink($rebuiltPath);
                throw new RuntimeException('Failed to read APK entry: ' . $name);
            }

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
                @unlink($rebuiltPath);
                throw new RuntimeException('Failed to write APK entry: ' . $name);
            }

            $method = (int)($stat['comp_method'] ?? -1);
            if (in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                $out->setCompressionName($name, $method);
            }

            unset($data);
        }

        $source->close();

        if (!$hasManifest) {
            $out->close();
            @unlink($rebuiltPath);
            throw new RuntimeException('The uploaded archive does not contain AndroidManifest.xml.');
        }

        if ($replacementCount <= 0) {
            $out->close();
            @unlink($rebuiltPath);
            throw new RuntimeException(
                'No supported Geometry Dash server URL was found in the APK.'
            );
        }

        if (!$out->close()) {
            @unlink($rebuiltPath);
            throw new RuntimeException('Failed to finalize the rebuilt APK.');
        }

        self::runTool(
            ['zipalign', '-P', '16', '-f', '4', $rebuiltPath, $alignedPath],
            'zipalign'
        );

        self::signApk($alignedPath, $outputPath);

        @unlink($rebuiltPath);
        @unlink($alignedPath);

        $outputSize = filesize($outputPath);
        if ($outputSize === false || $outputSize < 1024) {
            @unlink($outputPath);
            throw new RuntimeException('Signed APK output is invalid.');
        }

        self::verifyApk($outputPath);

        return [
            'server_url' => $server,
            'input_size' => $size,
            'output_size' => $outputSize,
            'replacement_count' => $replacementCount,
            'patched_entries' => $patchedEntries,
            'input_sha256' => hash_file('sha256', $inputPath) ?: '',
            'output_sha256' => hash_file('sha256', $outputPath) ?: '',
            'signed' => true,
        ];
    }

    private static function runTool(array $arguments, string $label): void
    {
        $binary = (string)array_shift($arguments);

        $command = $binary;
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string)$argument);
        }
        $command .= ' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException(
                $label . ' failed: ' . trim(implode("\n", $output))
            );
        }
    }

    private static function signerDirectory(): string
    {
        $configured = trim(
            (string)(
                getenv('MUCHO_ANDROID_SIGNER_DIR')
                ?: '/var/lib/muchocore/android-signer'
            )
        );

        if ($configured === '') {
            throw new RuntimeException('Android signer directory is not configured.');
        }

        if (
            (!is_dir($configured) && !@mkdir($configured, 0700, true)) ||
            !is_dir($configured)
        ) {
            throw new RuntimeException('Cannot create Android signer directory.');
        }

        @chmod($configured, 0700);

        return $configured;
    }

    private static function ensureSigner(): array
    {
        $dir = self::signerDirectory();
        $key = $dir . '/muchocore-android.key.pem';
        $cert = $dir . '/muchocore-android.cert.pem';

        if (!is_file($key) || !is_file($cert)) {
            @unlink($key);
            @unlink($cert);

            self::runTool(
                [
                    'openssl',
                    'genrsa',
                    '-out',
                    $key,
                    '2048',
                ],
                'Android signer key generation'
            );

            self::runTool(
                [
                    'openssl',
                    'req',
                    '-new',
                    '-x509',
                    '-sha256',
                    '-key',
                    $key,
                    '-out',
                    $cert,
                    '-days',
                    '10000',
                    '-subj',
                    '/CN=MuchoCore Android/O=MuchoCore/C=US',
                ],
                'Android signer certificate generation'
            );

            @chmod($key, 0600);
            @chmod($cert, 0644);
        }

        if (!is_readable($key) || !is_readable($cert)) {
            throw new RuntimeException('Android signing credentials are not readable.');
        }

        return [
            'key' => $key,
            'cert' => $cert,
        ];
    }

    private static function signApk(string $inputPath, string $outputPath): void
    {
        $signer = self::ensureSigner();

        self::runTool(
            [
                'apksigner',
                'sign',
                '--min-sdk-version',
                '7',
                '--v1-signing-enabled',
                'true',
                '--v2-signing-enabled',
                'true',
                '--v3-signing-enabled',
                'true',
                '--v4-signing-enabled',
                'false',
                '--key',
                $signer['key'],
                '--cert',
                $signer['cert'],
                '--out',
                $outputPath,
                $inputPath,
            ],
            'APK signing'
        );
    }

    private static function verifyApk(string $path): void
    {
        self::runTool(
            [
                'apksigner',
                'verify',
                '--verbose',
                $path,
            ],
            'APK signature verification'
        );

        $verify = new ZipArchive();
        if ($verify->open($path) !== true) {
            throw new RuntimeException(
                'Signed APK could not be reopened after signing.'
            );
        }

        if ($verify->locateName('AndroidManifest.xml') === false) {
            $verify->close();
            throw new RuntimeException(
                'Signed APK is missing AndroidManifest.xml.'
            );
        }

        $verify->close();
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

            /*
             * Preserve the scheme used by the original client string.
             * GD 1.0 is hard-coded to HTTP and the legacy GDPS transport
             * intentionally remains available over plain HTTP.
             */
            $oldParsed = parse_url($old);
            $targetBase = $base;

            if (
                is_array($oldParsed) &&
                isset($oldParsed['scheme'])
            ) {
                $targetBase =
                    strtolower((string)$oldParsed['scheme']) .
                    '://' .
                    (string)$parsed['host'] .
                    (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');
            }

            $new = self::compatibleUrl($targetBase, $length, false);

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
