<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

final readonly class CompatibilityProfile
{
    /** @var array<int, string> */
    private const SUPPORTED = [
        19 => 'GD 1.9',
        20 => 'GD 2.0',
        21 => 'GD 2.1',
        22 => 'GD 2.2',
    ];

    /** @var list<int> */
    public array $versions;

    public function __construct(array $versions)
    {
        $normalized = [];

        foreach ($versions as $version) {
            $version = (int)$version;

            if (isset(self::SUPPORTED[$version])) {
                $normalized[$version] = true;
            }
        }

        $this->versions = array_keys($normalized);
        sort($this->versions, SORT_NUMERIC);
    }

    public static function fromEnvironment(): self
    {
        $raw = trim(
            (string)(
                $_ENV['MUCHO_GD_VERSIONS']
                ?? getenv('MUCHO_GD_VERSIONS')
                ?? 'all'
            )
        );

        if ($raw === '' || strtolower($raw) === 'all') {
            return new self(array_keys(self::SUPPORTED));
        }

        $parts = preg_split('/[\\s,;]+/', $raw) ?: [];
        return new self(
            array_map(
                static fn(string $value): int => self::parseVersion($value),
                $parts
            )
        );
    }

    public function allows(ClientVersion $client): bool
    {
        if (!$client->isKnown()) {
            /*
             * Versionless endpoints exist in real legacy clients.
             * Unknown requests must not be rejected here because doing so
             * would break clients whose endpoint does not carry version data.
             */
            return true;
        }

        return in_array(
            $client->effectiveGameVersion(),
            $this->versions,
            true
        );
    }

    public function isAll(): bool
    {
        return count($this->versions) === count(self::SUPPORTED)
            && $this->versions === array_keys(self::SUPPORTED);
    }

    public function envValue(): string
    {
        if ($this->isAll()) {
            return 'all';
        }

        return implode(',', $this->versions);
    }

    public function label(): string
    {
        if ($this->isAll()) {
            return 'GD 1.9 - GD 2.2 (all supported versions)';
        }

        return implode(
            ', ',
            array_map(
                static fn(int $version): string =>
                    self::SUPPORTED[$version],
                $this->versions
            )
        );
    }

    /** @return array<int, string> */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    private static function parseVersion(string $value): int
    {
        $value = strtolower(trim($value));
        $value = ltrim($value, 'gd');

        if (str_contains($value, '.')) {
            [$major, $minor] = array_pad(
                explode('.', $value, 3),
                2,
                '0'
            );

            return ((int)$major * 10) + (int)$minor;
        }

        return (int)$value;
    }
}
