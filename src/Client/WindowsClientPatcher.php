<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;

final class WindowsClientPatcher
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

    public static function validateServerUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException('Server URL is required.');
        }

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $value = rtrim($value, '/');
        $parsed = parse_url($value);

        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid server URL.');
        }

        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = (string)($parsed['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Server URL must use http:// or https://.');
        }

        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new RuntimeException('Server URL must contain a valid hostname.');
        }

        if (
            isset($parsed['user']) ||
            isset($parsed['pass']) ||
            isset($parsed['query']) ||
            isset($parsed['fragment']) ||
            isset($parsed['path']) && $parsed['path'] !== ''
        ) {
            throw new RuntimeException(
                'Enter the server root only, for example https://gdps.example.com.'
            );
        }

        $port = isset($parsed['port']) ? ':' . (int)$parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    public static function patchFile(
        string $inputPath,
        string $outputPath,
        string $serverUrl
    ): array {
        $server = self::validateServerUrl($serverUrl);

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Uploaded executable cannot be read.');
        }

        $size = filesize($inputPath);
        if ($size === false || $size < 1024) {
            throw new RuntimeException('Uploaded file is too small to be a Geometry Dash executable.');
        }

        $head = file_get_contents($inputPath, false, null, 0, 512);
        if (!is_string($head) || !self::isPeFileHeader($head)) {
            throw new RuntimeException('The uploaded file is not a valid Windows PE executable.');
        }

        if ($outputPath === $inputPath) {
            throw new RuntimeException('Output path must be different from input path.');
        }

        $replacements = self::buildReplacements($server);

        if ($replacements === []) {
            throw new RuntimeException(
                'The supplied server URL is too long for the supported fixed-size client URL fields.'
            );
        }

        $stats = [];
        self::streamReplaceFixed(
            $inputPath,
            $outputPath,
            $replacements,
            $stats
        );

        if (filesize($outputPath) !== $size) {
            @unlink($outputPath);
            throw new RuntimeException('Patched executable size changed unexpectedly.');
        }

        if (!self::fileContainsAny($outputPath, array_values($replacements))) {
            @unlink($outputPath);
            throw new RuntimeException('The target server hostname was not found in the patched executable.');
        }

        $totalReplacements = array_sum($stats);

        if ($totalReplacements <= 0) {
            @unlink($outputPath);
            throw new RuntimeException(
                'No supported Geometry Dash server URL was found in this executable.'
            );
        }

        return [
            'server_url' => $server,
            'input_size' => $size,
            'output_size' => filesize($outputPath) ?: $size,
            'replacements' => $stats,
            'replacement_count' => $totalReplacements,
            'input_sha256' => hash_file('sha256', $inputPath) ?: '',
            'output_sha256' => hash_file('sha256', $outputPath) ?: '',
        ];
    }

    private static function isPeFileHeader(string $head): bool
    {
        if (strlen($head) < 0x40 || substr($head, 0, 2) !== 'MZ') {
            return false;
        }

        $peOffset = unpack('V', substr($head, 0x3c, 4))[1] ?? -1;

        return $peOffset >= 0x40 &&
            $peOffset + 4 <= strlen($head) &&
            substr($head, $peOffset, 4) === "PE\0\0";
    }

    /**
     * @return array<string,string>
     */
    private static function buildReplacements(string $server): array
    {
        $parsed = parse_url($server);
        $base = is_array($parsed)
            ? ((string)$parsed['scheme'] . '://' . (string)$parsed['host'] .
                (isset($parsed['port']) ? ':' . (int)$parsed['port'] : ''))
            : $server;

        $map = [];

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

        foreach ($forms as $old => $fixedLength) {
            $length = $fixedLength ?? strlen($old);

            if ($fixedLength === null) {
                $length = strlen($old);
            }

            /*
             * Preserve the scheme used by the original client URL.
             * Older Geometry Dash binaries commonly use plain HTTP; forcing
             * those fixed-length fields to HTTPS can introduce TLS failures
             * unrelated to MuchoCore protocol compatibility.
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

            if ($new !== null) {
                self::addReplacement($map, $old, $new);
                self::addReplacement(
                    $map,
                    base64_encode($old),
                    base64_encode($new)
                );
                self::addReplacement(
                    $map,
                    self::asciiToUtf16Le($old),
                    self::asciiToUtf16Le($new)
                );
            }
        }

        $bareNew = self::compatibleUrl($base, 26, true);
        if ($bareNew !== null) {
            self::addReplacement(
                $map,
                'www.boomlings.com/database',
                $bareNew
            );
            self::addReplacement(
                $map,
                base64_encode('www.boomlings.com/database'),
                base64_encode($bareNew)
            );
        }

        return $map;
    }

    private static function asciiToUtf16Le(string $value): string
    {
        $result = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $result .= $value[$i] . "\\x00";
        }

        return $result;
    }

    private static function addReplacement(
        array &$map,
        string $old,
        string $new
    ): void {
        if ($old === '' || strlen($old) !== strlen($new)) {
            return;
        }

        $map[$old] = $new;
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
     * @param array<string,int> $stats
     */
    private static function streamReplaceFixed(
        string $input,
        string $output,
        array $replacements,
        array &$stats
    ): void {
        $in = fopen($input, 'rb');
        $out = fopen($output, 'wb');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new RuntimeException('Cannot open executable for patching.');
        }

        $maxLength = 1;
        foreach ($replacements as $old => $new) {
            $maxLength = max($maxLength, strlen($old), strlen($new));
            $stats[$new] = 0;
        }

        $buffer = '';
        $chunkSize = 4 * 1024 * 1024;
        $tailLength = max(0, $maxLength - 1);

        while (!feof($in)) {
            $piece = fread($in, $chunkSize);
            if ($piece === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while reading the executable.');
            }

            $buffer .= $piece;

            if (strlen($buffer) <= $tailLength) {
                continue;
            }

            $processLength = strlen($buffer) - $tailLength;
            $process = substr($buffer, 0, $processLength);
            $buffer = substr($buffer, $processLength);

            $process = self::replaceBuffer($process, $replacements, $stats);

            if (fwrite($out, $process) === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while writing the patched executable.');
            }
        }

        if ($buffer !== '') {
            $buffer = self::replaceBuffer($buffer, $replacements, $stats);
            if (fwrite($out, $buffer) === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while finalizing the patched executable.');
            }
        }

        fclose($in);
        fclose($out);
    }

    private static function replaceBuffer(
        string $buffer,
        array $replacements,
        array &$stats
    ): string {
        foreach ($replacements as $old => $new) {
            $count = substr_count($buffer, $old);

            if ($count > 0) {
                $buffer = str_replace($old, $new, $buffer);
                $stats[$new] += $count;
            }
        }

        return $buffer;
    }

    private static function fileContainsAny(
        string $path,
        array $needles
    ): bool {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $maxLength = 1;
        foreach ($needles as $needle) {
            $maxLength = max($maxLength, strlen($needle));
        }

        $tail = '';
        while (!feof($handle)) {
            $piece = fread($handle, 4 * 1024 * 1024);
            if ($piece === false) {
                fclose($handle);
                return false;
            }

            $data = $tail . $piece;
            foreach ($needles as $needle) {
                if (str_contains($data, $needle)) {
                    fclose($handle);
                    return true;
                }
            }

            $tail = substr($data, -($maxLength - 1));
        }

        fclose($handle);
        return false;
    }
}
