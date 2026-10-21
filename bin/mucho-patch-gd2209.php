<?php

declare(strict_types=1);

/**
 * MuchoCore native Windows GD 2.209 patch studio.
 *
 * CLI and authenticated web patching share the exact same SHA-pinned PHP
 * engine. Dry-run never writes the executable or contacts a remote server.
 *
 * php bin/mucho-patch-gd2209.php --input=/path/GeometryDash.exe --server=https://gdps.example.com --dry-run
 * php bin/mucho-patch-gd2209.php --input=/path/GeometryDash.exe --server=https://gdps.example.com --output=/path/GeometryDash-MuchoCore.exe --json
 */
require_once dirname(__DIR__) . '/src/Client/WindowsClientPatcher.php';

use MuchoCore\Client\Gd2209Binary;

function gd2209Usage(): void
{
    echo "MuchoCore | GD 2.209 exact-build patch studio\n\n";
    echo "Usage: php bin/mucho-patch-gd2209.php --input=GeometryDash.exe --server=https://your-gdps.example [--output=patched.exe] [--dry-run] [--json] [--manifest=report.json]\n";
    echo "  --dry-run   Validate source, compute hash and inspect all route rewrites; do not write client.\n";
    echo "  --json      Print the full route manifest as JSON.\n";
    echo "  --manifest  Save the report to a new JSON file (never overwrite).\n";
    echo "  --help      Show this usage.\n\n";
    echo "Source must be an exact known, unmodified GD 2.209 Windows x64 EXE.\n";
}

function gd2209WriteManifest(string $path, array $report): void
{
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Manifest already exists; choose another filename.');
    }
    $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
    $fp = @fopen($path, 'xb');
    if (!is_resource($fp)) {
        throw new RuntimeException('Unable to create manifest file.');
    }
    try {
        $written = fwrite($fp, $payload);
        if ($written !== strlen($payload)) {
            throw new RuntimeException('Unable to write complete manifest.');
        }
    } catch (Throwable $error) {
        fclose($fp);
        @unlink($path);
        throw $error;
    }
    fclose($fp);
}

function gd2209Cli(array $argv): int
{
    $options = getopt('', ['input:', 'server:', 'output:', 'dry-run', 'json', 'manifest:', 'help']);
    if ($options === false || isset($options['help'])) {
        gd2209Usage();
        return 0;
    }
    $input = $options['input'] ?? null;
    $server = $options['server'] ?? null;
    if (!is_string($input) || $input === '' ||
        !is_string($server) || $server === '') {
        gd2209Usage();
        fwrite(STDERR, "\nERROR: --input and --server are required.\n");
        return 2;
    }
    if (strtolower(pathinfo($input, PATHINFO_EXTENSION)) !== 'exe') {
        fwrite(STDERR, "ERROR: Expected an unmodified GeometryDash.exe file.\n");
        return 2;
    }
    $dryRun = array_key_exists('dry-run', $options);
    $output = $options['output'] ?? null;
    if ($output !== null && (!is_string($output) || $output === '')) {
        fwrite(STDERR, "ERROR: --output requires a filename.\n");
        return 2;
    }
    if ($output === null) {
        $output = dirname($input) . DIRECTORY_SEPARATOR .
            pathinfo($input, PATHINFO_FILENAME) . '-MuchoCore.exe';
    }
    if (!$dryRun && (file_exists($output) || is_link($output))) {
        fwrite(STDERR, "ERROR: Output already exists. Original and previous patches are preserved.\n");
        return 2;
    }

    try {
        $report = $dryRun
            ? Gd2209Binary::inspectFile($input, $server)
            : Gd2209Binary::patchFile($input, $output, $server);
        $report['dry_run'] = $dryRun;
        if (!$dryRun) {
            $report['output_path'] = $output;
        }
        if (isset($options['manifest'])) {
            if (!is_string($options['manifest']) || $options['manifest'] === '') {
                throw new RuntimeException('--manifest requires a filename.');
            }
            gd2209WriteManifest($options['manifest'], $report);
        }
        if (isset($options['json'])) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR) . "\n";
            return 0;
        }
        echo "MuchoCore GD 2.209 patch studio\n";
        echo "Source:     " . $report['source_build'] . "\n";
        echo "Server:     " . $report['server_url'] . "\n";
        echo "Endpoints:  " . $report['replacement_count'] . "\n";
        echo "Routes:     database=" . $report['route_modes']['database'] .
            " /a/=" . $report['route_modes']['short_alias'] .
            " root=" . $report['route_modes']['root_alias'] . "\n";
        echo "Input SHA:  " . $report['input_sha256'] . "\n";
        echo "Output SHA: " . $report['output_sha256'] . "\n";
        if ($report['requires_short_alias'] || $report['requires_root_alias']) {
            echo "IMPORTANT: Configure the matching short route aliases on your web server before launching the client.\n";
        }
        echo $dryRun
            ? "PREFLIGHT PASSED: no files were modified.\n"
            : "PATCH SAVED: " . $output . "\n";
        echo "NOTE: Static patching is verified; live client/server features are NOT certified.\n";
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, "PATCH FAILED: " . $exception->getMessage() . "\n");
        return 1;
    }
}

exit(gd2209Cli($argv));
