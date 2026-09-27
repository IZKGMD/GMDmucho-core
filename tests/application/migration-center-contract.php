<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$detector = file_get_contents($root . '/src/Migration/SourceDetector.php');
$wizard = file_get_contents($root . '/bin/mucho-migrate.php');
$control = file_get_contents($root . '/bin/mucho');
$workflow = file_get_contents($root . '/.github/workflows/validate.yml');
$docs = file_get_contents($root . '/docs/MIGRATION_CENTER.md');

foreach ([
    'src/Migration/SourceDetector.php' => $detector,
    'bin/mucho-migrate.php' => $wizard,
    'bin/mucho' => $control,
    '.github/workflows/validate.yml' => $workflow,
    'docs/MIGRATION_CENTER.md' => $docs,
] as $path => $content) {
    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
}

foreach ([
    'Cvolton',
    'GDPS-Maker',
    'MegaSa1nt',
    'FHGDPS',
    'Unknown GDPS schema',
    'imported_now',
    'detected_only',
    'filesystem',
] as $needle) {
    if (stripos($detector, $needle) === false) {
        throw new RuntimeException('Source detector contract missing: ' . $needle);
    }
}

foreach ([
    '--confirm=MIGRATE',
    'SET SESSION TRANSACTION READ ONLY',
    'DRY-RUN COMPLETE',
    'password_resets_required',
    'WHAT TO ENTER',
    'WHAT IS WHERE',
    'The destination will not be changed during this scan.',
    'Preparing MuchoCore schema...',
] as $needle) {
    if (strpos($wizard, $needle) === false) {
        throw new RuntimeException('Migration wizard contract missing: ' . $needle);
    }
}

$prepareAt = strpos($wizard, 'Preparing MuchoCore schema...');
$dryRunExitAt = strpos($wizard, 'if (!$requestedApply)');

if ($prepareAt === false || $dryRunExitAt === false || $prepareAt < $dryRunExitAt) {
    throw new RuntimeException(
        'Migration schema preparation must happen after the dry-run exit.'
    );
}

foreach ([
    'Database & migrations',
    'Migration Center',
    'bin/mucho-migrate.php',
] as $needle) {
    if (strpos($control, $needle) === false) {
        throw new RuntimeException('Control Center migration integration missing: ' . $needle);
    }
}

foreach ([
    'Old DB host',
    'Old DB name',
    'Comments',
    'Friends/requests/blocks/messages',
    'Music/SFX files',
    'Unknown sources',
] as $needle) {
    if (stripos($docs, $needle) === false) {
        throw new RuntimeException('Migration documentation missing: ' . $needle);
    }
}

echo "migration-center-contract: OK\n";
