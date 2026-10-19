<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Core\Environment;

function hotpathAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

// Do not modify a real .env: test the private file reader via reflection
// against an isolated temporary file.
$path = tempnam(sys_get_temp_dir(), 'mucho-env-');
if ($path === false) {
    throw new RuntimeException('Cannot create temporary env fixture.');
}
try {
    file_put_contents($path, "MC_HOTPATH_CHECK=first\n");
    $method = new ReflectionMethod(Environment::class, 'loadFile');
    $first = $method->invoke(null, $path);
    hotpathAssert(
        ($first['MC_HOTPATH_CHECK'] ?? null) === 'first',
        'environment parses a normal config file'
    );

    // Even if a file changes during a single request, all Environment::get
    // keys must see the same snapshot, avoiding repeated parsing and races.
    file_put_contents($path, "MC_HOTPATH_CHECK=second\n");
    $second = $method->invoke(null, $path);
    hotpathAssert(
        ($second['MC_HOTPATH_CHECK'] ?? null) === 'first',
        'environment file snapshot is parsed once per request'
    );

    $other = $path . '.other';
    file_put_contents($other, "MC_HOTPATH_CHECK=isolated\n");
    try {
        $different = $method->invoke(null, $other);
        hotpathAssert(
            ($different['MC_HOTPATH_CHECK'] ?? null) === 'isolated',
            'different files retain separate cached snapshots'
        );
    } finally {
        @unlink($other);
    }
} finally {
    @unlink($path);
}

$source = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Core/Application.php'
);
$constructor = explode('    public function handle(Request $request): Response', $source)[0] ?? '';
$handler = explode('    public function handle(Request $request): Response', $source)[1] ?? '';
hotpathAssert(
    !str_contains($constructor, 'new MuchoProtect()')
    && str_contains($handler, "if ((" . '$_SERVER' . "['MUCHO_PROTECT_PRECHECKED']")
    && str_contains($handler, '(new MuchoProtect())->inspect('),
    'prechecked requests skip duplicate guard construction but direct calls remain protected'
);

$front = (string)file_get_contents(
    dirname(__DIR__, 2) . '/public/index.php'
);
$guardSource = (string)file_get_contents(
    dirname(__DIR__, 2) . '/src/Security/MuchoProtect.php'
);
hotpathAssert(
    str_contains($front, '$guard = new MuchoProtect();')
    && str_contains($front, '(new Application($guard->storageConnection()))->run();')
    && str_contains($guardSource, '$this->storageConnection = $db;'),
    'front controller reuses the DB protection connection without bypassing inspection'
);
$constructorMethod = new ReflectionMethod(
    \MuchoCore\Core\Application::class, '__construct'
);
$pdoArg = $constructorMethod->getParameters()[0] ?? null;
hotpathAssert(
    $pdoArg !== null
    && $pdoArg->getType()?->getName() === PDO::class
    && $pdoArg->allowsNull(),
    'standalone Application remains backward compatible without an injected PDO'
);

echo "MUCHOCORE_HOTPATH_BOOTSTRAP_OK\n";
