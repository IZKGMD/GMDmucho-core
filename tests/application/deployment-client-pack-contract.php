<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pack = file_get_contents($root . '/src/Client/DeploymentClientPack.php');
$deploy = file_get_contents($root . '/public/deploy.php');
$console = file_get_contents($root . '/public/install/shared/go/index.html');

foreach ([
    [$pack, 'new \\ZipArchive()', 'client pack creates a ZIP archive'],
    [$pack, "'archive' => [", 'client pack manifest exposes archive metadata'],
    [$pack, "'windows/GeometryDash-MuchoGDPS.exe'", 'ZIP contains Windows client'],
    [$pack, "'android/GeometryDash-MuchoGDPS.apk'", 'ZIP contains Android client'],
    [$pack, "'client-pack.json'", 'ZIP contains public manifest'],
    [$pack, "'files' => [", 'public manifest uses file inventory'],
    [$deploy, "!in_array($kind, ['windows', 'android', 'zip'], true)", 'deploy API accepts ZIP downloads'],
    [$deploy, "'kind=zip'", 'deploy API publishes ZIP download URL'],
    [$deploy, "application/zip", 'deploy API streams ZIP with correct media type'],
    [$console, 'Client Pack (.zip)', 'deployment console exposes unified ZIP download'],
    [$console, 'Individual downloads are also available.', 'deployment console keeps individual client downloads'],
] as [$haystack, $needle, $description]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fwrite(STDERR, "Contract failed: {$description}
");
        exit(1);
    }
}

if (
    str_contains($pack, "'files' => [\n                    [\n                        'name' => 'windows/") === false ||
    str_contains($pack, "'path' => $windowsPath") === false
) {
    fwrite(STDERR, "Contract failed: public ZIP manifest is missing the expected inventory/path separation
");
    exit(1);
}

echo "Deployment client pack contract passed
";
