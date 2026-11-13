<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use JsonException;
use RuntimeException;

/** Keep deletion audit metadata, not password-derived or reusable secrets. */
final class DeletedAccountSnapshot
{
    public static function sanitizeAccount(array $account): array
    {
        foreach (array_keys($account) as $key) {
            // Protect future account schema additions as well as both
            // password_hash and gjp2_hash in the current accounts table.
            if (preg_match('/password|passphrase|credential|gjp|secret|token|private[_-]?key|passkey|salt/i', (string)$key) === 1) {
                unset($account[$key]);
            }
        }

        return $account;
    }

    public static function sanitizeJson(string $snapshot): string
    {
        $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['account']) || !is_array($data['account'])) {
            throw new RuntimeException('Invalid deleted-account snapshot.');
        }

        $data['account'] = self::sanitizeAccount($data['account']);
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
