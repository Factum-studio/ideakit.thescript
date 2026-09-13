<?php

declare(strict_types=1);

namespace modules\users\application\port;

use DateTimeImmutable;
use modules\users\application\dto\ResolvedUserIdentityContext;
use modules\users\application\exception\UserIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionIntegrityException;
use modules\users\application\exception\UserIdentityResolutionPersistenceException;
use modules\users\domain\valueObject\TelegramUserId;

interface IUserIdentityResolver
{
    /**
     * @throws UserIdentityResolutionConcurrencyException
     * @throws UserIdentityResolutionIntegrityException
     * @throws UserIdentityResolutionPersistenceException
     */
    public function resolve(
        TelegramUserId $telegramUserId,
        DateTimeImmutable $resolvedAt,
    ): ResolvedUserIdentityContext;
}
