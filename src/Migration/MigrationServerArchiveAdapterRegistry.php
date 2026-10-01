<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use RuntimeException;

final class MigrationServerArchiveAdapterRegistry
{
    public function __construct(
        private readonly string $root
    ) {}

    public function resolveUpload(
        array $upload
    ): MigrationServerArchiveAdapterInterface {
        foreach ($this->adapterClasses() as $class) {
            /** @var MigrationServerArchiveAdapterInterface $adapter */
            $adapter = new $class($this->root);

            if ($adapter->supportsUpload($upload)) {
                return $adapter;
            }
        }

        throw new RuntimeException(
            'No server archive adapter supports the uploaded archive.'
        );
    }

    /**
     * @return list<MigrationServerArchiveAdapterInterface>
     */
    public function instances(): array
    {
        return array_map(
            fn(string $class): MigrationServerArchiveAdapterInterface => new $class($this->root),
            $this->adapterClasses()
        );
    }

    /**
     * @return list<class-string<MigrationServerArchiveAdapterInterface>>
     */
    private function adapterClasses(): array
    {
        return [
            GalaxxyServerArchiveAdapter::class,
        ];
    }
}
