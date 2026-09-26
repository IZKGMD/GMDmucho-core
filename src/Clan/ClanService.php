<?php

declare(strict_types=1);

namespace MuchoCore\Clan;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;

final readonly class ClanService
{
    public function __construct(
        private PDO $pdo,
        private AccountAuthenticator $auth,
        private ClanRepository $repository
    ) {}

    public function create(
        int $accountId,
        string $credential,
        string $name,
        string $tag,
        string $description='',
        bool $isOpen=true,
        int $maxMembers=50
    ): array {
        $this->auth->authenticate($accountId,$credential);

        if ($this->repository->getForAccount($accountId)!==null) {
            throw new RuntimeException('Account is already in a clan.');
        }

        $name=$this->normalizeName($name);
        $tag=$this->normalizeTag($tag);
        $description=$this->normalizeDescription($description);
        $maxMembers=max(2,min(500,$maxMembers));

        if ($name==='' || $tag==='') {
            throw new RuntimeException('Invalid clan name or tag.');
        }

        try {
            return $this->repository->create(
                $accountId,
                $name,
                $tag,
                $description,
                $isOpen,
                $maxMembers
            );
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0)===1062) {
                throw new RuntimeException('Clan name or tag already exists.');
            }

            throw $e;
        }
    }

    public function get(int $accountId,string $credential,int $clanId): array {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getById($clanId);

        if ($clan===null) {
            throw new RuntimeException('Clan not found.');
        }

        $clan['members']=$this->repository->members($clanId);
        return $clan;
    }

    public function myClan(int $accountId,string $credential): ?array {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan!==null) {
            $clan['members']=$this->repository->members((int)$clan['clan_id']);
        }

        return $clan;
    }

    public function search(int $accountId,string $credential,string $query): array {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->search(trim($query),0,20);
    }

    public function join(int $accountId,string $credential,int $clanId): bool {
        $this->auth->authenticate($accountId,$credential);

        if ($this->repository->getForAccount($accountId)!==null) {
            throw new RuntimeException('Account is already in a clan.');
        }

        $clan=$this->repository->getById($clanId);
        if ($clan===null || (int)$clan['is_open']!==1) {
            throw new RuntimeException('Clan is not open for joining.');
        }

        if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
            throw new RuntimeException('Clan is full.');
        }

        try {
            return $this->repository->join($clanId,$accountId);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0)===1062) {
                throw new RuntimeException('Account is already in a clan.');
            }

            throw $e;
        }
    }

    public function leave(int $accountId,string $credential): bool {
        $this->auth->authenticate($accountId,$credential);
        $clan=$this->repository->getForAccount($accountId);

        if ($clan===null) {
            return false;
        }

        if ((string)$clan['role']==='owner') {
            throw new RuntimeException('Clan owner cannot leave the clan.');
        }

        return $this->repository->removeMember((int)$clan['clan_id'],$accountId);
    }

    public function invite(int $accountId,string $credential,int $targetAccountId): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        if ($clan===null || !in_array((string)$clan['role'],['owner','officer'],true)) {
            throw new RuntimeException('Clan officer permission required.');
        }

        if ($targetAccountId<=0 || $targetAccountId===$accountId) {
            throw new RuntimeException('Invalid invite target.');
        }

        if ($this->repository->getForAccount($targetAccountId)!==null) {
            throw new RuntimeException('Target account is already in a clan.');
        }

        if ((int)$clan['member_count'] >= (int)$clan['max_members']) {
            throw new RuntimeException('Clan is full.');
        }

        return $this->repository->invite((int)$clan['clan_id'],$targetAccountId,$accountId);
    }

    public function acceptInvite(int $accountId,string $credential,int $inviteId): bool {
        $this->auth->authenticate($accountId,$credential);

        if ($this->repository->getForAccount($accountId)!==null) {
            throw new RuntimeException('Account is already in a clan.');
        }

        $invite=$this->repository->getInvite($inviteId,$accountId);
        if ($invite===null) {
            throw new RuntimeException('Clan invitation not found or expired.');
        }

        $clan=$this->repository->getById((int)$invite['clan_id']);
        if ($clan===null || (int)$clan['member_count'] >= (int)$clan['max_members']) {
            throw new RuntimeException('Clan is full or unavailable.');
        }

        $this->pdo->beginTransaction();

        try {
            $insert=$this->pdo->prepare(
                "INSERT INTO mucho_clan_members (clan_id, account_id, role)
                 VALUES (:clan_id, :account_id, 'member')"
            );
            $insert->execute([
                'clan_id'=>(int)$invite['clan_id'],
                'account_id'=>$accountId,
            ]);

            $delete=$this->pdo->prepare(
                'DELETE FROM mucho_clan_invites
                 WHERE invite_id=:invite_id AND account_id=:account_id'
            );
            $delete->execute([
                'invite_id'=>$inviteId,
                'account_id'=>$accountId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function declineInvite(int $accountId,string $credential,int $inviteId): bool {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->deleteInvite($inviteId,$accountId);
    }

    public function kick(int $accountId,string $credential,int $targetAccountId): bool {
        $this->auth->authenticate($accountId,$credential);

        $clan=$this->repository->getForAccount($accountId);
        $target=$this->repository->getForAccount($targetAccountId);

        if ($clan===null || $target===null || (int)$clan['clan_id']!==(int)$target['clan_id']) {
            throw new RuntimeException('Target is not in your clan.');
        }

        if (!in_array((string)$clan['role'],['owner','officer'],true)) {
            throw new RuntimeException('Clan officer permission required.');
        }

        if ((string)$target['role']==='owner') {
            throw new RuntimeException('The clan owner cannot be kicked.');
        }

        if ((string)$clan['role']==='officer' && (string)$target['role']!=='member') {
            throw new RuntimeException('Officers cannot remove other officers.');
        }

        return $this->repository->removeMember((int)$clan['clan_id'],$targetAccountId);
    }

    public function setRole(int $accountId,string $credential,int $targetAccountId,string $role): bool {
        $this->auth->authenticate($accountId,$credential);

        if (!in_array($role,['officer','member'],true)) {
            throw new RuntimeException('Invalid clan role.');
        }

        $clan=$this->repository->getForAccount($accountId);
        $target=$this->repository->getForAccount($targetAccountId);

        if (
            $clan===null ||
            $target===null ||
            (int)$clan['clan_id']!==(int)$target['clan_id'] ||
            (string)$clan['role']!=='owner' ||
            (string)$target['role']==='owner'
        ) {
            throw new RuntimeException('Clan owner permission required.');
        }

        return $this->repository->setRole(
            (int)$clan['clan_id'],
            $targetAccountId,
            $role
        );
    }

    public function invites(int $accountId,string $credential): array {
        $this->auth->authenticate($accountId,$credential);
        return $this->repository->invitations($accountId);
    }

    private function normalizeName(string $name): string {
        $name=trim(preg_replace('/\s+/',' ',$name) ?? '');

        return strlen($name)<=24 &&
            preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$/D',$name)===1
            ? $name
            : '';
    }

    private function normalizeTag(string $tag): string {
        $tag=strtoupper(trim($tag));

        return strlen($tag)<=6 &&
            preg_match('/^[A-Z0-9]{2,6}$/D',$tag)===1
            ? $tag
            : '';
    }

    private function normalizeDescription(string $description): string {
        return substr(
            trim(preg_replace('/\s+/',' ',$description) ?? ''),
            0,
            160
        );
    }
}
