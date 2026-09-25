#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

php -r '
require "vendor/autoload.php";
use MuchoCore\Release\ReleaseService;

$tests = [
    ["1.0.1", "1.0.1", 0],
    ["1.0.1", "1.0.2", -1],
    ["1.2.0", "1.1.9", 1],
    ["v2.0.0", "1.9.9", 1],
];

foreach ($tests as [$left, $right, $expected]) {
    $actual = ReleaseService::compareVersions($left, $right);
    if (($actual <=> 0) !== ($expected <=> 0)) {
        fwrite(STDERR, "Version comparison failed: {$left} vs {$right}\n");
        exit(1);
    }
}

$ref = new ReflectionClass(ReleaseService::class);
if ($ref->getName() !== "MuchoCore\\Release\\ReleaseService") {
    exit(1);
}

echo "release-update-detection: PASS\n";
'