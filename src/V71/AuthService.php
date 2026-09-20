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

        // 2.2+ sends gjp2; older clients commonly send gjp.
        $credential = Request::string('gjp2');

        if ($credential === '') {
            $credential = Request::string('gjp');
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
