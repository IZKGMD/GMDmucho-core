<?php
/** Account-bound cloud saves in the host's local database; no public files. */
declare(strict_types=1);

function mucho7_cloudsave(PDO $pdo, $data, $backup)
{
    $aid = mucho7_identity($pdo, $data, true);
    if ($aid <= 0) { return '-1'; }
    if ($backup) {
        $save = isset($data['saveData']) ? $data['saveData'] : null;
        // MEDIUMBLOB and bounded form request fit small shared-host processes.
        if (!is_string($save) || $save === '' || strlen($save) > 8*1024*1024) {
            return '-1';
        }
        $query = $pdo->prepare('INSERT INTO php7_cloud_saves(account_id,save_data,sha256)
            VALUES(:id,:data,:hash) ON DUPLICATE KEY UPDATE
            save_data=VALUES(save_data),sha256=VALUES(sha256),updated_at=CURRENT_TIMESTAMP');
        $query->bindValue(':id', $aid, PDO::PARAM_INT);
        $query->bindValue(':data', $save, PDO::PARAM_LOB);
        $query->bindValue(':hash', hash('sha256', $save));
        $query->execute();
        return '1';
    }
    $query = $pdo->prepare('SELECT save_data,sha256 FROM php7_cloud_saves WHERE account_id=?');
    $query->execute(array($aid));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row || !is_string($row['save_data']) || $row['save_data'] === ''
        || !hash_equals($row['sha256'], hash('sha256', $row['save_data']))) { return '-1'; }
    $save = $row['save_data'];
    return strpos($save, ';21;30;a;a') === false ? $save . ';21;30;a;a' : $save;
}
