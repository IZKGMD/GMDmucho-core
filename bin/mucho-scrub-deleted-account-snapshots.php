<?php
declare(strict_types=1);

/**
 * Manual repair for old deletion archives that were written before credential
 * redaction existed. Read-only by default. Does not touch backup archives.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/public/admin/core/DeletedAccountSnapshot.php';

use MuchoCore\Admin\DeletedAccountSnapshot;
use MuchoCore\Backup\DatabaseBackupVerifier;
use MuchoCore\Database\Database;

$apply = in_array('--apply', $argv, true);
$confirmed = in_array('--confirm=REDACT', $argv, true);
$backup = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--verified-backup=')) {
        $backup = substr($arg, strlen('--verified-backup='));
    }
}

if ($apply) {
    if (!$confirmed || $backup === '' || !is_file($backup) ||
        (int)filemtime($backup) < time() - 7200) {
        fwrite(STDERR, "Refusing changes: --apply requires --confirm=REDACT and a verified SQL backup less than two hours old.\n");
        exit(2);
    }

    // This checks the full gzip and exact SHA-256 sidecar before mutations.
    DatabaseBackupVerifier::verify($backup);
}

$db = (new Database())->connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$existing = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$total = 0;

foreach (['admin_deleted_accounts', 'admin_deleted_accounts_v4'] as $table) {
    if (!in_array($table, $existing, true)) {
        continue;
    }

    // Table names come from this hard-coded allowlist, never user input.
    $lastId = 0;
    $affected = 0;
    do {
        $read = $db->prepare("SELECT id,snapshot FROM `{$table}` WHERE id > :last ORDER BY id ASC LIMIT 100");
        $read->bindValue(':last', $lastId, PDO::PARAM_INT);
        $read->execute();
        $rows = $read->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        if ($apply) {
            $db->beginTransaction();
        }
        try {
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $raw = (string)$row['snapshot'];
                $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record) || !isset($record['account']) ||
                    !is_array($record['account'])) {
                    throw new RuntimeException("Invalid archived account record at {$table} #{$lastId}; repair stopped.");
                }
                $clean = DeletedAccountSnapshot::sanitizeAccount($record['account']);
                if ($clean === $record['account']) {
                    continue;
                }

                $affected++;
                if ($apply) {
                    $updated = DeletedAccountSnapshot::sanitizeJson($raw);
                    $write = $db->prepare("UPDATE `{$table}` SET snapshot=:clean WHERE id=:id AND BINARY snapshot=BINARY :original");
                    $write->execute(['clean' => $updated, 'id' => $lastId, 'original' => $raw]);
                    if ($write->rowCount() !== 1) {
                        throw new RuntimeException("Concurrent deletion snapshot update at {$table} #{$lastId}; repair stopped.");
                    }
                }
            }

            if ($apply) {
                $db->commit();
            }
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    } while (count($rows) === 100);

    echo $table . ': ' . $affected . ($apply ? ' redacted' : ' need redaction') . PHP_EOL;
    $total += $affected;
}

echo ($apply ? 'REDACTION_APPLIED=' : 'REDACTION_DRY_RUN=') . $total . PHP_EOL;
