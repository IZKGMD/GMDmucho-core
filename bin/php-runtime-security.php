<?php

declare(strict_types=1);

/**
 * Runtime security baseline for the critical PHP CVE-2026-109618 and
 * CVE-2026-109619 fixes released 2026-10-21.
 *
 * This is a minimum-version guard, not a claim that every newer release
 * is free from other vulnerabilities. Unknown PHP branches fail closed
 * until explicitly reviewed.
 */
function muchoPhpMinimumSecurityRelease(string $version): ?string
{
    if (preg_match('/^(\d+)\.(\d+)(?:\.|$)/D', $version, $matches) !== 1) {
        return null;
    }

    $minimum = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    return $minimum[$matches[1] . '.' . $matches[2]] ?? null;
}

function muchoPhpMeetsSecurityBaseline(string $version): bool
{
    $minimum = muchoPhpMinimumSecurityRelease($version);
    return $minimum !== null && version_compare($version, $minimum, '>=');
}

if (isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $minimum = muchoPhpMinimumSecurityRelease(PHP_VERSION);
    if ($minimum === null) {
        fwrite(STDERR, '[MuchoCore] Unsupported PHP runtime for the 2026-10-21 security baseline: ' . PHP_VERSION . PHP_EOL);
        exit(1);
    }
    if (!muchoPhpMeetsSecurityBaseline(PHP_VERSION)) {
        fwrite(STDERR, '[MuchoCore] PHP ' . PHP_VERSION . ' is below the required ' .
            $minimum . ' security release (CVE-2026-109618 / CVE-2026-109619). ' .
            'Pull a patched PHP base image and rebuild app/worker.' . PHP_EOL);
        exit(1);
    }
    echo '[MuchoCore] PHP ' . PHP_VERSION . ' meets the 2026-10-21 security baseline (' .
        $minimum . '+).' . PHP_EOL;
}
