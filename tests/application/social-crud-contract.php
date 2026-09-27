<?php

declare(strict_types=1);

$source = (string)file_get_contents(
    __DIR__ . '/../../src/Social/RelationshipRepository.php'
);

function methodBody(string $source, string $name): string
{
    $start = strpos($source, 'public function ' . $name);
    if ($start === false) {
        throw new RuntimeException("Could not locate {$name}()");
    }

    $open = strpos($source, '{', $start);
    if ($open === false) {
        throw new RuntimeException("Could not locate {$name}() body");
    }

    $depth = 0;
    $length = strlen($source);

    for ($i = $open; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open + 1, $i - $open - 1);
            }
        }
    }

    throw new RuntimeException("Could not close {$name}() body");
}

$read = methodBody($source, 'readRequest');
if (!str_contains($read, 'SELECT 1')) {
    throw new RuntimeException('readRequest must distinguish missing requests from already-read requests');
}
if (!str_contains($read, 'return true;')) {
    throw new RuntimeException('readRequest should remain idempotently successful for an existing request');
}

$delete = methodBody($source, 'deleteRequest');
if (!str_contains($delete, '$q->rowCount() > 0')) {
    throw new RuntimeException('deleteRequest must report whether a request was actually removed');
}

$remove = methodBody($source, 'removeFriend');
if (!str_contains($remove, '$q->rowCount() > 0')) {
    throw new RuntimeException('removeFriend must report whether a friendship was actually removed');
}

$unblock = methodBody($source, 'unblock');
if (!str_contains($unblock, '$q->rowCount() > 0')) {
    throw new RuntimeException('unblock must report whether a block was actually removed');
}

echo "social-crud-contract: OK\n";
