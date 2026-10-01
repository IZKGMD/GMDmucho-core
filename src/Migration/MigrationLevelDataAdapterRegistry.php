<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use RuntimeException;

final class MigrationLevelDataAdapterRegistry
{
    public function resolveUpload(
        array $upload
    ): MigrationLevelDataAdapterInterface {
        foreach ($this->adapterClasses() as $class) {
            /** @var MigrationLevelDataAdapterInterface $adapter */
            $adapter = new $class();

            if ($adapter->supportsUpload($upload)) {
                return $adapter;
            }
        }

        throw new RuntimeException(
            'No external level-data adapter supports the uploaded archive.'
        );
    }

    /**
     * @return list<class-string<MigrationLevelDataAdapterInterface>>
     */
    public function adapters(): array
    {
        return $this->adapterClasses();
    }

    /**
     * @return list<class-string<MigrationLevelDataAdapterInterface>>
     */
    private function adapterClasses(): array
    {
        return [
            GalaxxyFilesystemLevelDataAdapter::class,
        ];
    }
}
