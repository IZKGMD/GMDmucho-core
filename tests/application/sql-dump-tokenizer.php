<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/src/Migration/SqlDumpTokenizer.php';

$handle = fopen('php://memory', 'w+');

fwrite(
    $handle,
    "-- header\n" .
    "SET NAMES utf8mb4;\n" .
    "CREATE TABLE `accounts` (name VARCHAR(64));\n" .
    "INSERT INTO `accounts` VALUES ('one;two');\n" .
    "/* comment; inside */ INSERT INTO `accounts` VALUES ('three');\n"
);
rewind($handle);

$statements = iterator_to_array(
    \MuchoCore\Migration\SqlDumpTokenizer::statements($handle)
);
fclose($handle);

if (count($statements) !== 4) {
    throw new RuntimeException(
        'Expected 4 SQL statements, got ' . count($statements) . '.'
    );
}

if (!str_contains($statements[2], "'one;two'")) {
    throw new RuntimeException('Tokenizer split a semicolon inside a quoted SQL value.');
}

if (!str_starts_with($statements[0], 'SET NAMES')) {
    throw new RuntimeException('Tokenizer lost the first SQL statement.');
}

echo "sql-dump-tokenizer: OK\n";
