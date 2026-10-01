<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;
use RuntimeException;

final class MigrationDatabaseAdapterRegistry
{
    public function resolve(
        PDO $target,
        string $sourcePrefix,
        array $inspection
    ): MigrationDatabaseAdapterInterface {
        foreach ($this->adapterClasses() as $class) {
            /** @var MigrationDatabaseAdapterInterface $adapter */
            $adapter = new $class($target, $sourcePrefix);

            if ($adapter->supports($inspection)) {
                return $adapter;
            }
        }

        throw new RuntimeException(
            'No migration database adapter supports the detected source schema.'
        );
    }

    /**
     * @return list<class-string<MigrationDatabaseAdapterInterface>>
     */
    private function adapterClasses(): array
    {
        return [
            CvoltonMigrationAdapter::class,
        ];
    }
}
