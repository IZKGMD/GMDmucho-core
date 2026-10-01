<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

interface MigrationDatabaseAdapterInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * @param array<string,mixed> $inspection
     */
    public function supports(array $inspection): bool;

    /**
     * @return array<string,int>
     */
    public function preflight(PDO $source): array;

    /**
     * @return array<string,int>
     */
    public function apply(PDO $source): array;
}
