<?php

declare(strict_types=1);

namespace modules\users\application\port;

use core\domain\valueObject\UserIdentityId;
use modules\users\application\dto\VersionedTelegramIdentityProfile;
use modules\users\application\exception\TelegramIdentityProfileAlreadyExistsException;
use modules\users\application\exception\TelegramIdentityProfileConcurrencyException;
use modules\users\application\exception\TelegramIdentityProfileNotFoundException;
use modules\users\application\exception\TelegramIdentityProfilePersistenceException;
use modules\users\domain\entity\TelegramIdentityProfile;
use modules\users\domain\valueObject\TelegramIdentityProfileId;

interface ITelegramIdentityProfileRepository
{
    /**
     * @throws TelegramIdentityProfilePersistenceException
     */
    public function findById(
        TelegramIdentityProfileId $id,
    ): ?VersionedTelegramIdentityProfile;

    /**
     * @throws TelegramIdentityProfilePersistenceException
     */
    public function findByUserIdentityId(
        UserIdentityId $userIdentityId,
    ): ?VersionedTelegramIdentityProfile;

    /**
     * @throws TelegramIdentityProfileAlreadyExistsException
     * @throws TelegramIdentityProfilePersistenceException
     */
    public function add(
        TelegramIdentityProfile $profile,
    ): VersionedTelegramIdentityProfile;

    /**
     * @throws TelegramIdentityProfileNotFoundException
     * @throws TelegramIdentityProfileConcurrencyException
     * @throws TelegramIdentityProfilePersistenceException
     */
    public function save(
        TelegramIdentityProfile $profile,
        int $expectedLockVersion,
    ): VersionedTelegramIdentityProfile;
}
