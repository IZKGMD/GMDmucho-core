<?php

declare (strict_types=1);
namespace MuchoCore\CloudSave;

use MuchoCore\Core\Environment;
use PDO;
use RuntimeException;
use Throwable;
/*
 * MuchoCore Secure Cloud Save Repository
 * Copyright (C) 2026 IZK
 */
final class CloudSaveRepository
{
    const KEY_FILE = '/var/lib/muchocore/cloudsave.key';
    const HISTORY_LIMIT = 5;
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    public function save(int $accountId, string $saveData): int
    {
        $hash = hash('sha256', $saveData);
        $size = strlen($saveData);
        $encrypted = $this->encrypt($saveData);
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare("\n                SELECT\n                    account_id,\n                    ciphertext,\n                    nonce,\n                    auth_tag,\n                    sha256,\n                    size_bytes,\n                    revision,\n                    backed_up_at\n\n                FROM mucho_cloud_saves\n\n                WHERE account_id=:account\n\n                FOR UPDATE\n            ");
            $q->execute(['account' => $accountId]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            /*
             * Identical cloud save:
             * don't create useless history revision.
             */
            if ($old && hash_equals((string) $old['sha256'], $hash)) {
                $touch = $this->db->prepare("\n                    UPDATE mucho_cloud_saves\n\n                    SET backed_up_at=CURRENT_TIMESTAMP\n\n                    WHERE account_id=:account\n                ");
                $touch->execute(['account' => $accountId]);
                $revision = (int) $old['revision'];
                $this->db->commit();
                return $revision;
            }
            $revision = 1;
            if ($old) {
                $revision = (int) $old['revision'] + 1;
                $archive = $this->db->prepare("\n                    INSERT IGNORE INTO\n                    mucho_cloud_save_revisions\n                    (\n                        account_id,\n                        revision,\n                        ciphertext,\n                        nonce,\n                        auth_tag,\n                        sha256,\n                        size_bytes,\n                        original_backed_up_at\n                    )\n                    VALUES\n                    (\n                        :account,\n                        :revision,\n                        :ciphertext,\n                        :nonce,\n                        :tag,\n                        :sha256,\n                        :size,\n                        :backed_up\n                    )\n                ");
                $archive->execute(['account' => $accountId, 'revision' => (int) $old['revision'], 'ciphertext' => $old['ciphertext'], 'nonce' => $old['nonce'], 'tag' => $old['auth_tag'], 'sha256' => $old['sha256'], 'size' => (int) $old['size_bytes'], 'backed_up' => $old['backed_up_at']]);
            }
            $upsert = $this->db->prepare("\n                INSERT INTO mucho_cloud_saves\n                (\n                    account_id,\n                    ciphertext,\n                    nonce,\n                    auth_tag,\n                    sha256,\n                    size_bytes,\n                    revision,\n                    backed_up_at\n                )\n                VALUES\n                (\n                    :account,\n                    :ciphertext,\n                    :nonce,\n                    :tag,\n                    :sha256,\n                    :size,\n                    :revision,\n                    CURRENT_TIMESTAMP\n                )\n\n                ON DUPLICATE KEY UPDATE\n\n                    ciphertext=VALUES(ciphertext),\n                    nonce=VALUES(nonce),\n                    auth_tag=VALUES(auth_tag),\n                    sha256=VALUES(sha256),\n                    size_bytes=VALUES(size_bytes),\n                    revision=VALUES(revision),\n                    backed_up_at=CURRENT_TIMESTAMP\n            ");
            $upsert->bindValue(':account', $accountId, PDO::PARAM_INT);
            $upsert->bindValue(':ciphertext', $encrypted['ciphertext'], PDO::PARAM_LOB);
            $upsert->bindValue(':nonce', $encrypted['nonce'], PDO::PARAM_LOB);
            $upsert->bindValue(':tag', $encrypted['tag'], PDO::PARAM_LOB);
            $upsert->bindValue(':sha256', $hash);
            $upsert->bindValue(':size', $size, PDO::PARAM_INT);
            $upsert->bindValue(':revision', $revision, PDO::PARAM_INT);
            $upsert->execute();
            /*
             * Keep last five previous revisions.
             */
            $history = $this->db->prepare("\n                SELECT history_id\n\n                FROM mucho_cloud_save_revisions\n\n                WHERE account_id=:account\n\n                ORDER BY revision DESC\n\n                LIMIT 100\n            ");
            $history->execute(['account' => $accountId]);
            $ids = array_map('intval', $history->fetchAll(PDO::FETCH_COLUMN));
            if (count($ids) > self::HISTORY_LIMIT) {
                foreach (array_slice($ids, self::HISTORY_LIMIT) as $historyId) {
                    $delete = $this->db->prepare("\n                        DELETE FROM\n                        mucho_cloud_save_revisions\n\n                        WHERE history_id=:id\n                          AND account_id=:account\n                    ");
                    $delete->execute(['id' => $historyId, 'account' => $accountId]);
                }
            }
            $this->db->commit();
            return $revision;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    public function load(int $accountId)
    {
        $q = $this->db->prepare("\n            SELECT\n                ciphertext,\n                nonce,\n                auth_tag,\n                sha256,\n                size_bytes\n\n            FROM mucho_cloud_saves\n\n            WHERE account_id=:account\n\n            LIMIT 1\n        ");
        $q->execute(['account' => $accountId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $plain = $this->decrypt((string) $row['ciphertext'], (string) $row['nonce'], (string) $row['auth_tag']);
        if (strlen($plain) !== (int) $row['size_bytes']) {
            throw new RuntimeException('Cloud save size integrity failure.');
        }
        if (!hash_equals((string) $row['sha256'], hash('sha256', $plain))) {
            throw new RuntimeException('Cloud save checksum failure.');
        }
        return $plain;
    }
    private function encrypt(string $plain): array
    {
        $key = $this->key();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = \mucho7_openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Cloud save encryption failed.');
        }
        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
    }
    private function decrypt(string $ciphertext, string $nonce, string $tag): string
    {
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Invalid cloud save cryptographic metadata.');
        }
        $plain = \mucho7_openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Cloud save decryption failed.');
        }
        return $plain;
    }
    /**
     * VPS deployments keep the key in a protected Docker volume. The
     * no-Docker shared-hosting installer instead creates config/cloudsave.key.
     * Never generate a new key here: rotating it would make old saves unreadable.
     */
    private function keyPath(): string
    {
        $configured = trim((string) (Environment::get('MUCHO_CLOUDSAVE_KEY_FILE', '') ?? ''));
        if ($configured !== '') {
            if (!str_starts_with($configured, '/') || preg_match('/[\x00-\x1F\x7F]/', $configured) === 1) {
                throw new RuntimeException('Cloud save key path must be an absolute private filesystem path.');
            }
            return $configured;
        }
        if (Environment::get('MUCHO_SHARED_HOSTING', '0') === '1') {
            return dirname(\MUCHO7_PROJECT_ROOT . '/src/CloudSave', 2) . '/config/cloudsave.key';
        }
        return self::KEY_FILE;
    }
    private function key(): string
    {
        $raw = @file_get_contents($this->keyPath());
        if ($raw === false) {
            throw new RuntimeException('Cloud save key unavailable.');
        }
        $key = base64_decode(trim($raw), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid cloud save key.');
        }
        return $key;
    }
    private $db;
}
