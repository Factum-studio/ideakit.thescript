<?php

declare(strict_types=1);

namespace core\application\port;

use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;

interface IUserIdentityRepository
{
    public function save(UserIdentity $identity): void;
    public function findById(UserIdentityId $id): ?UserIdentity;
    public function findByProviderAndClientId(string $provider, string $clientId): ?UserIdentity;
    /**
     * @return UserIdentity[]
     */
    public function findByUserId(UserId $userId): array;
    public function deleteByUserId(UserId $userId): void;
}
