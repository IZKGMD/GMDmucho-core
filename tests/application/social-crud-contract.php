<?php

declare(strict_types=1);

$source = (string)file_get_contents(
    __DIR__ . '/../../src/Social/RelationshipRepository.php'
);

function methodBody(string $source, string $name, string $next): string
{
    $pattern = '/public function ' . preg_quote($name, '/') .
        '\\([^)]*\\).*?\\n    \\{(?P<body>.*?)\\n    \\}\\n\\n    public function ' .
        preg_quote($next, '/') . '/s';

    if (!preg_match($pattern, $source, $match)) {
        throw new RuntimeException("Could not locate {$name}()");
    }

    return $match['body'];
}

$read = methodBody($source, 'readRequest', 'accept');
if (!str_contains($read, 'SELECT 1')) {
    throw new RuntimeException('readRequest must distinguish missing requests from already-read requests');
}
if (!str_contains($read, 'return true;')) {
    throw new RuntimeException('readRequest should remain idempotently successful for an existing request');
}

$delete = methodBody($source, 'deleteRequest', 'removeFriend');
if (!str_contains($delete, '$q->rowCount() > 0')) {
    throw new RuntimeException('deleteRequest must report whether a request was actually removed');
}

$remove = methodBody($source, 'removeFriend', 'block');
if (!str_contains($remove, '$q->rowCount() > 0')) {
    throw new RuntimeException('removeFriend must report whether a friendship was actually removed');
}

$unblock = methodBody($source, 'unblock', 'userList');
if (!str_contains($unblock, '$q->rowCount() > 0')) {
    throw new RuntimeException('unblock must report whether a block was actually removed');
}

echo "social-crud-contract: OK\n";
