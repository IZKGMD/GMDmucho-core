<?php

declare(strict_types=1);

/* Copyright (C) 2026 IZK */

/**
 * Static guard against reintroducing the removed accounts.role column.
 *
 * The migration is the only first-party file allowed to mention the legacy
 * column because it intentionally converts old installations to role_id.
 */

$root = dirname(__DIR__);

$scanRoots = [
    $root . '/src',
    $root . '/public/admin',
    $root . '/bin',
];

$allowedLegacyFiles = [
    realpath($root . '/database/migrations/20260920_002_role_schema.php'),
];

$patterns = [
    '/\baccounts\s+set\s+[^;\n]*\brole\s*=/i',
    '/\bselect\s+role\s+from\s+accounts\b/i',
    '/\ba\.role\b/i',
    '/\baccounts\.role\b/i',
    '/\baccounts\s*\(\s*[^)]*\brole\b/i',
];

$violations = [];

foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $scanRoot,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = realpath($file->getPathname());

        if ($path === false || in_array($path, $allowedLegacyFiles, true)) {
            continue;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            fwrite(STDERR, "Unable to read {$path}\n");
            exit(1);
        }

        $normalized = preg_replace('/\\s+/', ' ', $content) ?? $content;

        foreach ($patterns as $pattern) {
            if (
                preg_match($pattern, $content) === 1 ||
                preg_match($pattern, $normalized) === 1
            ) {
                $violations[] = $path . ' matches ' . $pattern;
                break;
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Legacy role column references found:\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, " - {$violation}\n");
    }

    exit(1);
}

echo "ROLE_SCHEMA_OK\n";
