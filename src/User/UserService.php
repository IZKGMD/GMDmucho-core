<?php

declare(strict_types=1);

namespace MuchoCore\User;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Account\AccountRepository;
use MuchoCore\Protocol\GdUserEncoder;
use MuchoCore\User\GameRole;
use PDO;
use RuntimeException;

final readonly class UserService
{
    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private AccountRepository $accountRepository,
        private UserRepository $userRepository,
        private GdUserEncoder $userEncoder
    ) {}

    public function updateScore(int $accountId, string $gjp, array $data): string
    {
        $this->auth->authenticate($accountId, $gjp);

        /*
         * Keep the modern Mucho schema compatible while applying the
         * validation rules from fix-2356. Optional fields are written only
         * when the current profiles table actually contains them.
         */
        $fields = [
            'game_version' => $this->boundedInt($data, ['gameVersion'], 22, 0, 1000),
            'binary_version' => $this->boundedInt($data, ['binaryVersion'], 0, 0, 10000),
            'stars' => $this->boundedInt($data, ['stars'], 0, 0, 10_000_000),
            'moons' => $this->boundedInt($data, ['moons'], 0, 0, 10_000_000),
            'demons' => $this->boundedInt($data, ['demons'], 0, 0, 1_000_000),
            'diamonds' => $this->boundedInt($data, ['diamonds'], 0, 0, 100_000_000),
            'secret_coins' => $this->boundedInt($data, ['coins'], 0, 0, 1_000_000),
            'user_coins' => $this->boundedInt($data, ['userCoins'], 0, 0, 1_000_000),

            'icon_id' => $this->boundedInt(
                $data,
                ['icon', 'accIcon'],
                1,
                0,
                65535
            ),

            'icon_type' => $this->boundedInt(
                $data,
                ['iconType'],
                0,
                0,
                32
            ),

            'cube' => $this->boundedInt($data, ['accIcon'], 1, 0, 65535),
            'ship' => $this->boundedInt($data, ['accShip'], 1, 0, 65535),
            'ball' => $this->boundedInt($data, ['accBall'], 1, 0, 65535),
            'ufo' => $this->boundedInt($data, ['accBird'], 1, 0, 65535),
            'wave' => $this->boundedInt($data, ['accDart'], 1, 0, 65535),
            'robot' => $this->boundedInt($data, ['accRobot'], 1, 0, 65535),
            'spider' => $this->boundedInt($data, ['accSpider'], 1, 0, 65535),
            'swing' => $this->boundedInt($data, ['accSwing'], 1, 0, 65535),
            'jetpack' => $this->boundedInt($data, ['accJetpack'], 1, 0, 65535),

            'explosion' => $this->boundedInt(
                $data,
                ['accExplosion'],
                1,
                0,
                65535
            ),

            'color1' => $this->boundedInt($data, ['color1'], 0, 0, 65535),
            'color2' => $this->boundedInt($data, ['color2'], 3, 0, 65535),
            'color3' => $this->boundedInt($data, ['color3'], 0, -1, 32767),

            'special' => $this->boundedInt(
                $data,
                ['accGlow', 'special', 'accSpecial'],
                0,
                0,
                1
            )
        ];

        $schema = $this->pdo->query('SHOW COLUMNS FROM profiles');

        if ($schema === false) {
            throw new RuntimeException('Unable to inspect profile schema.');
        }

        $profileColumns = $schema->fetchAll(PDO::FETCH_COLUMN);

        if ($profileColumns === []) {
            throw new RuntimeException('Profile schema is empty.');
        }

        $available = array_fill_keys(
            array_map('strval', $profileColumns),
            true
        );

        $fields = array_filter(
            $fields,
            static fn (mixed $value, string $column): bool =>
                isset($available[$column]),
            ARRAY_FILTER_USE_BOTH
        );

        if ($fields === []) {
            throw new RuntimeException(
                'No compatible profile fields are available.'
            );
        }

        $columns = array_keys($fields);

        $quotedColumns = array_map(
            static fn (string $column): string => '`' . $column . '`',
            $columns
        );

        $placeholders = array_map(
            static fn (string $column): string => ':' . $column,
            $columns
        );

        $updates = array_map(
            static fn (string $column): string =>
                sprintf('`%1$s` = VALUES(`%1$s`)', $column),
            $columns
        );

        $sql = sprintf(
            'INSERT INTO profiles (`account_id`, %s)
             VALUES (:account_id, %s)
             ON DUPLICATE KEY UPDATE %s',
            implode(', ', $quotedColumns),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        $stmt = $this->pdo->prepare($sql);

        if (!$stmt->execute(
            array_merge(
                ['account_id' => $accountId],
                $fields
            )
        )) {
            throw new RuntimeException('Unable to update profile.');
        }

        return (string)$accountId;
    }

    public function updateSettings(
        int $accountId,
        string $gjp,
        int $mS,
        int $frS,
        int $cS,
        string $yt,
        string $twitter,
        string $twitch
    ): bool {
        $this->auth->authenticate($accountId, $gjp);

        $q = $this->pdo->prepare(
            'UPDATE accounts SET
                messages_state=:ms,
                friend_requests_state=:fr,
                comments_state=:cs,
                youtube_url=:yt,
                twitter=:tw,
                twitch=:tt
             WHERE account_id=:id'
        );

        return $q->execute([
            'ms' => max(0, min(2, $mS)),
            'fr' => max(0, min(2, $frS)),
            'cs' => max(0, min(2, $cS)),
            'yt' => substr(trim($yt), 0, 255),
            'tw' => substr(trim($twitter), 0, 64),
            'tt' => substr(trim($twitch), 0, 64),
            'id' => $accountId,
        ]);
    }

    public function requestAccess(int $accountId, string $gjp): string
    {
        $this->auth->authenticate($accountId, $gjp);

        $q = $this->pdo->prepare('SELECT COALESCE(r.code, \'user\') AS role
             FROM accounts a
             LEFT JOIN roles r ON r.id = a.role_id
             WHERE a.account_id = :id
             LIMIT 1');
        $q->execute(['id' => $accountId]);

        try {
            return GameRole::accessLevel((string)$q->fetchColumn());
        } catch (\\InvalidArgumentException) {
            return '-1';
        }
    }

    public function search(string $query, int $page = 0): string
    {
        $page = min(1000, max(0, $page));
        $limit = 10;
        $offset = $page * $limit;
        $users = $this->userRepository->search($query, $offset, $limit);

        return $this->userEncoder->search($users, count($users), $offset, $limit);
    }

    public function resolveAccountIdByUserId(int $userId): int
    {
        return $this->userRepository->findAccountIdByUserId(
            $userId
        );
    }

    public function getProfile(int $targetAccountId): string
    {
        $profile = $this->userRepository->getProfileByTarget($targetAccountId);
        if (!$profile) {
            return '-1';
        }

        return $this->userEncoder->profile($profile);
    }

    public function getLeaderboard(string $type, int $accountId = 0, int $limit = 100): string
    {
        $users = match ($type) {
            'creators' => $this->userRepository->leaderboardCreators($limit),
            'relative' => $this->userRepository->leaderboardRelative($accountId, 50),
            default    => $this->userRepository->leaderboardTop($limit)
        };

        return $this->userEncoder->leaderboard($users);
    }

    /**
     * @param list<string> $keys
     */
    private function boundedInt(
        array $data,
        array $keys,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = null;

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                break;
            }
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (
            !is_int($value) &&
            (
                !is_string($value) ||
                preg_match('/^-?\d{1,10}$/D', $value) !== 1
            )
        ) {
            throw new RuntimeException('Invalid profile value.');
        }

        $value = (int)$value;

        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException(
                'Profile value is outside the allowed range.'
            );
        }

        return $value;
    }

}
