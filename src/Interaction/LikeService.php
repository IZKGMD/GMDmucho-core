<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;

final class LikeService
{
    public function __construct(
        private readonly LikeRepository $repository,
        private readonly AccountAuthenticator $auth
    ) {}

    public function likeItem(int $itemId, int $type, int $accountId, string $gjp, bool $isLike): void
    {
        // Проверяем токен (gjp), чтобы нельзя было накручивать лайки от чужого имени
        $this->auth->authenticate($accountId, $gjp);
        
        // Пытаемся добавить лайк. Если уже голосовал, репозиторий вернет false, 
        // но мы не будем выдавать ошибку клиенту, игра просто проигнорирует действие.
        $this->repository->addLike($itemId, $type, $accountId, $isLike);
    }
}
