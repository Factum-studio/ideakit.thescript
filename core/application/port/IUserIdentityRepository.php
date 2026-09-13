<?php

declare(strict_types=1);

namespace core\application\port;

use core\application\exception\UserIdentityConcurrencyException;
use core\application\exception\UserIdentityResolutionPersistenceException;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;

interface IUserIdentityRepository
{
    /**
     * @throws UserIdentityConcurrencyException
     * @throws UserIdentityResolutionPersistenceException
     */
    public function save(UserIdentity $identity): void;

    /**
     * @throws UserIdentityResolutionPersistenceException
     */
    public function findById(UserIdentityId $id): ?UserIdentity;

    /**
     * @throws UserIdentityResolutionPersistenceException
     */
    public function findByProviderAndClientId(string $provider, string $clientId): ?UserIdentity;

    /**
     * @return UserIdentity[]
     * @throws UserIdentityResolutionPersistenceException
     */
    public function findByUserId(UserId $userId): array;

    /**
     * @throws UserIdentityResolutionPersistenceException
     */
    public function deleteByUserId(UserId $userId): void;
}
