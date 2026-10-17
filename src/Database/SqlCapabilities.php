<?php
declare(strict_types=1);

namespace MuchoCore\Database;

use PDO;

/**
 * Feature detection for SQL dialect differences on budget shared hosting.
 *
 * A version string is not permission to run an untested migration. This
 * capability is used only by repository read-only query fallbacks.
 */
final class SqlCapabilities
{
    public static function supportsWindowFunctions(PDO $pdo): bool
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        return self::supportsWindowFunctionsForVersion($version);
    }

    public static function supportsWindowFunctionsForVersion(string $raw): bool
    {
        $maria = stripos($raw, 'mariadb') !== false;
        preg_match_all('/\d+\.\d+(?:\.\d+)?/', $raw, $matches);
        $versions = $matches[0] ?? [];
        if ($maria) {
            // Some MariaDB-compatible servers prefix their actual version
            // with "5.5.5-" for MySQL driver compatibility.
            $versions = array_values(array_filter(
                $versions,
                static fn (string $v): bool => $v !== '5.5.5'
            ));
        }
        $version = $versions[0] ?? '';
        if ($version === '') {
            // Fail closed: an unknown SQL server is not assumed compatible.
            return false;
        }

        return $maria
            ? version_compare($version, '10.2.0', '>=')
            : version_compare($version, '8.0.0', '>=');
    }
}
