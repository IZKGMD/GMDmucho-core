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

// The .env.local overlay must remain correct and be merged once per
// request even if the files are changed between unrelated config reads.
$projectFixture = sys_get_temp_dir() . '/mucho-config-' . bin2hex(random_bytes(6));
if (!mkdir($projectFixture, 0700)) {
    throw new RuntimeException('Cannot create project env fixture');
}
try {
    file_put_contents($projectFixture . '/.env', "MC_SOURCE=base\nMC_OVERLAY=base\n");
    file_put_contents($projectFixture . '/.env.local', "MC_OVERLAY=local\n");
    $projectMethod = new ReflectionMethod(Environment::class, 'projectFileConfig');
    $firstProject = $projectMethod->invoke(null, $projectFixture);
    hotpathAssert(
        ($firstProject['MC_SOURCE'] ?? null) === 'base'
        && ($firstProject['MC_OVERLAY'] ?? null) === 'local',
        'project environment preserves .env.local override precedence'
    );

    file_put_contents($projectFixture . '/.env.local', "MC_OVERLAY=changed\n");
    $againProject = $projectMethod->invoke(null, $projectFixture);
    hotpathAssert(
        $againProject === $firstProject,
        'project overlay is snapshotted once for each PHP request'
    );
} finally {
    @unlink($projectFixture . '/.env.local');
    @unlink($projectFixture . '/.env');
    @rmdir($projectFixture);
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
    && str_contains($front, '(new Application($guard->storageConnection(), $request))->run($request);')
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

$run = new ReflectionMethod(\MuchoCore\Core\Application::class, 'run');
$param = $run->getParameters()[0] ?? null;
hotpathAssert(
    $param !== null
    && $param->allowsNull()
    && $param->isDefaultValueAvailable()
    && $param->getType()?->getName() === \MuchoCore\Http\Request::class
    && substr_count($front, 'Request::fromGlobals()') === 1,
    'HTTP requests are parsed once, then reused after MuchoProtect precheck'
);

echo "MUCHOCORE_HOTPATH_BOOTSTRAP_OK\n";
