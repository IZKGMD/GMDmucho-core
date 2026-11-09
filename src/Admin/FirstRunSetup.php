<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use PDO;
use RuntimeException;
use Throwable;

/**
 * One-time, race-safe owner onboarding.
 *
 * The installer's password hash authorizes the web form. The installer never
 * creates an admin account behind the owner's back; the final owner credentials
 * are chosen on the first visit to /admin/.
 */
final class FirstRunSetup
{
    public static function prepare(PDO $db, int $existingAdmins): bool
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_initialization (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $sealed = (bool)$db->query(
            'SELECT 1 FROM admin_initialization WHERE id=1 LIMIT 1'
        )->fetchColumn();

        // Upgraded installations with administrators must never become
        // publicly claimable if an operator later removes every account.
        // Avoid a redundant write on every authenticated admin page load.
        if (!$sealed && $existingAdmins > 0) {
            $db->exec('INSERT IGNORE INTO admin_initialization (id) VALUES (1)');
            return true;
        }

        return $sealed;
    }

    /**
     * @return array{id:int,username:string,role:string}|null
     * Null means another request already completed setup.
     */
    public static function createOwner(PDO $db, string $username, string $password): ?array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/D', $username)) {
            throw new RuntimeException('Invalid administrator username.');
        }

        if (strlen($password) < 12 || strlen($password) > 256 ||
            preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new RuntimeException('Invalid administrator password.');
        }

        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'muchocore.admin.setup.'.substr(hash('sha256', $database), 0, 16);
        $lock = $db->prepare('SELECT GET_LOCK(:name, 5)');
        $lock->execute(['name' => $lockName]);

        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Administrator setup is busy. Please retry.');
        }

        try {
            $count = (int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($count !== 0 || self::prepare($db, 0)) {
                return null;
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare(
                    "INSERT INTO admin_users (username,password_hash,role,is_active)
                     VALUES (:username,:hash,'owner',1)"
                );
                $stmt->execute([
                    'username' => $username,
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $id = (int)$db->lastInsertId();
                $db->exec('INSERT INTO admin_initialization (id) VALUES (1)');
                $db->commit();

                return ['id' => $id, 'username' => $username, 'role' => 'owner'];
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }
}
