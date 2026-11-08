<?php

declare(strict_types=1);

/**
 * PHP 8 only. Examples:
 * php bin/mucho-audit-votes.php
 * php bin/mucho-audit-votes.php --max-targets=20000 --max-votes=50000
 *
 * This tool never executes INSERT, UPDATE, DELETE or schema migrations.
 * Logs aggregate counts only; target IDs are not printed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use MuchoCore\Database\Database;
use MuchoCore\Interaction\VoteIntegrityAuditor;

$limits = [
    'max-targets' => 5000,
    'max-votes' => 20000,
    'batch' => 200,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo "Usage: php bin/mucho-audit-votes.php [--max-targets=N] [--max-votes=N] [--batch=1..500]\n";
        exit(0);
    }
    if (!preg_match('/^--(max-targets|max-votes|batch)=([1-9][0-9]*)$/D', $arg, $match)) {
        fwrite(STDERR, "Invalid argument. Use --help.\n");
        exit(64);
    }
    $name = $match[1];
    $value = filter_var($match[2], FILTER_VALIDATE_INT);
    if ($value === false || $value > 100000 || ($name === 'batch' && $value > 500)) {
        fwrite(STDERR, "Audit limit outside safe bounds.\n");
        exit(64);
    }
    $limits[$name] = $value;
}

try {
    $summary = (new VoteIntegrityAuditor((new Database())->connection()))->scan(
        $limits['max-targets'],
        $limits['max-votes'],
        $limits['batch']
    );

    // Do not emit any target IDs to shared/public logs (including bridge issue).
    $drift = 0;
    $complete = $summary['votes']['complete'];
    $schemaComplete = true;
    foreach ($summary['targets'] as &$item) {
        $drift += $item['mismatches'];
        $complete = $complete && $item['complete'];
        $schemaComplete = $schemaComplete && $item['exists'];
        unset($item['samples']);
    }
    unset($item);
    $summary['summary'] = [
        'score_mismatches' => $drift,
        'complete' => $complete && $schemaComplete,
        'needs_review' => $drift > 0 ||
            $summary['votes']['orphaned'] > 0 ||
            $summary['votes']['self_votes'] > 0 ||
            $summary['votes']['invalid_values'] > 0 ||
            $summary['votes']['unknown_types'] > 0 ||
            !$schemaComplete,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) .
        PHP_EOL;

    if ($summary['summary']['needs_review']) {
        exit(2);
    }
    if (!$summary['summary']['complete']) {
        exit(3); // Audited a safe bound, not necessarily the entire database.
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Vote audit failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
