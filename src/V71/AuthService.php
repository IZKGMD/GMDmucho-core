<?php
declare(strict_types=1);

namespace MuchoCore\V71;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use Throwable;

final class AuthService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function validateLevelSecret(): bool
    {
        return hash_equals(
            'Wmfd2893gb7',
            Request::string('secret')
        );
    }

    public function authenticatedAccountId(): ?int
    {
        $accountId = Request::int('accountID');
        $gameVersion = Request::int('gameVersion', 0);

        // 2.2+ prefers gjp2; older clients commonly use gjp.
        if ($gameVersion >= 22) {
            $credential = Request::string('gjp2');

            if ($credential === '') {
                $credential = Request::string('gjp');
            }
        } else {
            $credential = Request::string('gjp');

            if ($credential === '') {
                $credential = Request::string('gjp2');
            }
        }

        if ($accountId <= 0 || $credential === '') {
            return null;
        }

        try {
            (new AccountAuthenticator($this->db))
                ->authenticate($accountId, $credential);

            return $accountId;
        } catch (Throwable) {
            return null;
        }
    }
}
