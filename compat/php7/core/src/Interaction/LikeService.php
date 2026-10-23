<?php

declare (strict_types=1);
namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;
final class LikeService
{
    public function __construct(LikeRepository $repository, AccountAuthenticator $auth)
    {
        $this->repository = $repository;
        $this->auth = $auth;
    }
    public function likeItem(int $itemId, int $type, int $accountId, string $gjp, bool $isLike)
    {
        $this->auth->authenticate($accountId, $gjp);
        $this->repository->addLike($itemId, $type, $accountId, $isLike, '');
    }
    public function likeAnonymous(int $itemId, int $type, string $ip)
    {
        $this->repository->addLike($itemId, $type, 0, true, $ip);
    }
    private $repository;
    private $auth;
}
