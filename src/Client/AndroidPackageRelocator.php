<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;

/**
 * Change the installable Android application ID, not Java class names.
 * Keeping activity class names unchanged is necessary: the compiled DEX
 * still contains com.robtopx.geometryjump.GeometryJump.
 *
 * This is an exact-length AXML/string-pool + ARSC package-table rewrite,
 * deliberately refusing unfamiliar/obfuscated layouts instead of blindly
 * replacing bytes anywhere in an APK.
 */
final class AndroidPackageRelocator
{
    public const SOURCE_ID = 'com.robtopx.geometryjump';
    public const SIDE_BY_SIDE_ID = 'com.muchogd.geometryjump';

    private static function le16(string $s, int $p): int
    {
        if ($p < 0 || $p + 2 > strlen($s)) {
            throw new RuntimeException('Truncated Android binary resource.');
        }
        return unpack('v', substr($s, $p, 2))[1];
    }

    private static function le32(string $s, int $p): int
    {
        if ($p < 0 || $p + 4 > strlen($s)) {
            throw new RuntimeException('Truncated Android binary resource.');
        }
        return unpack('V', substr($s, $p, 4))[1];
    }

    private static function utf16Ascii(string $value): string
    {
        $result = '';
        for ($i = 0; $i < strlen($value); $i++) {
            $result .= $value[$i] . "\0";
        }
        return $result;
    }

    public static function manifestPackage(string $binaryXml): string
    {
        return self::readOrChangeManifest($binaryXml, null)[1];
    }

    public static function relocateManifest(string $binaryXml): string
    {
        return self::readOrChangeManifest($binaryXml, self::SIDE_BY_SIDE_ID)[0];
    }

    /** @return array{string,string} */
    private static function readOrChangeManifest(string $xml, ?string $target): array
    {
        if (strlen($xml) < 36 ||
            self::le16($xml, 0) !== 0x0003 ||
            self::le32($xml, 4) !== strlen($xml)) {
            throw new RuntimeException('Unsupported compiled AndroidManifest.xml.');
        }

        $poolOffset = 8;
        if (self::le16($xml, $poolOffset) !== 0x0001) {
            throw new RuntimeException('Android manifest string pool not found.');
        }
        $header = self::le16($xml, $poolOffset + 2);
        $poolSize = self::le32($xml, $poolOffset + 4);
        $count = self::le32($xml, $poolOffset + 8);
        $flags = self::le32($xml, $poolOffset + 16);
        $stringsStart = self::le32($xml, $poolOffset + 20);
        if ($header < 28 || $poolOffset + $poolSize > strlen($xml) ||
            $count > 100000 || $header + $count * 4 > $poolSize ||
            ($flags & 0x100) !== 0) {
            throw new RuntimeException('Unsupported Android manifest string-pool layout.');
        }

        $old = self::utf16Ascii(self::SOURCE_ID);
        $new = self::utf16Ascii(self::SIDE_BY_SIDE_ID);
        if (strlen($old) !== strlen($new)) {
            throw new RuntimeException('Side-by-side package ID must preserve binary string length.');
        }

        $matches = 0;
        $alreadyRelocated = 0;
        for ($i = 0; $i < $count; $i++) {
            $relative = self::le32($xml, $poolOffset + $header + $i * 4);
            $pos = $poolOffset + $stringsStart + $relative;
            if ($pos + 2 > $poolOffset + $poolSize) {
                throw new RuntimeException('Android manifest string outside pool.');
            }
            $length = self::le16($xml, $pos);
            $lenBytes = 2;
            if (($length & 0x8000) !== 0) {
                $length = (($length & 0x7fff) << 16) | self::le16($xml, $pos + 2);
                $lenBytes = 4;
            }
            $start = $pos + $lenBytes;
            if ($length > 8192 || $start + $length * 2 + 2 > $poolOffset + $poolSize) {
                throw new RuntimeException('Malformed Android manifest string.');
            }
            $value = substr($xml, $start, $length * 2);
            if ($length === strlen(self::SIDE_BY_SIDE_ID) && $value === $new) {
                $alreadyRelocated++;
                continue;
            }
            if ($length !== strlen(self::SOURCE_ID) || $value !== $old) {
                continue;
            }
            $matches++;
            if ($target !== null) {
                $xml = substr_replace($xml, $new, $start, strlen($old));
            }
        }
        if ($target === null && $matches === 0 && $alreadyRelocated === 1) {
            return [$xml, self::SIDE_BY_SIDE_ID];
        }
        if ($matches !== 1 || $alreadyRelocated !== 0) {
            throw new RuntimeException('Expected one original GD package identifier in AndroidManifest.xml.');
        }
        return [$xml, $target ?? self::SOURCE_ID];
    }

    public static function relocateResources(string $arsc): string
    {
        if (strlen($arsc) < 12 || self::le16($arsc, 0) !== 0x0002 ||
            self::le32($arsc, 4) !== strlen($arsc)) {
            throw new RuntimeException('Unsupported Android resources.arsc table.');
        }
        $source = self::utf16Ascii(self::SOURCE_ID);
        $replacement = self::utf16Ascii(self::SIDE_BY_SIDE_ID);
        $offset = self::le16($arsc, 2);
        $matches = 0;
        while ($offset + 8 <= strlen($arsc)) {
            $type = self::le16($arsc, $offset);
            $header = self::le16($arsc, $offset + 2);
            $size = self::le32($arsc, $offset + 4);
            if ($size < $header || $size < 8 || $offset + $size > strlen($arsc)) {
                throw new RuntimeException('Malformed Android resource package table.');
            }
            if ($type === 0x0200) {
                if ($header < 268) {
                    throw new RuntimeException('Android package chunk missing its name.');
                }
                $pos = $offset + 12;
                $actual = substr($arsc, $pos, 256);
                if (substr($actual, 0, strlen($source) + 2) === $source . "\0\0") {
                    $arsc = substr_replace($arsc, $replacement, $pos, strlen($source));
                    $matches++;
                }
            }
            $offset += $size;
        }
        if ($matches !== 1) {
            throw new RuntimeException('Original GD package not found in resources.arsc.');
        }
        return $arsc;
    }
}
