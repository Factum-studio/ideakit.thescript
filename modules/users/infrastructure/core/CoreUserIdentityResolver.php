<?php

declare(strict_types=1);

namespace modules\users\infrastructure\core;

use core\application\command\ResolveUserIdentityCommand;
use core\application\exception\UserIdentityConcurrencyException as CoreConcurrencyException;
use core\application\exception\UserIdentityOwnerNotFoundException as CoreIntegrityException;
use core\application\exception\UserIdentityResolutionPersistenceException as CorePersistenceException;
use core\application\handler\ResolveUserIdentityHandler;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use modules\users\application\dto\ResolvedUserIdentityContext;
use modules\users\application\enum\UserAccountStatus;
use modules\users\application\exception\UserIdentityResolutionConcurrencyException;
use modules\users\application\exception\UserIdentityResolutionIntegrityException;
use modules\users\application\exception\UserIdentityResolutionPersistenceException;
use modules\users\application\port\IUserIdentityResolver;
use modules\users\domain\valueObject\TelegramUserId;
use Throwable;

final class CoreUserIdentityResolver implements IUserIdentityResolver
{
    private const TELEGRAM_PROVIDER = 'telegram';

    public function __construct(
        private readonly ResolveUserIdentityHandler $handler,
    ) {
    }

    public function resolve(
        TelegramUserId $telegramUserId,
        DateTimeImmutable $resolvedAt,
    ): ResolvedUserIdentityContext {
        try {
            $resolvedIdentity = $this->handler->handle(new ResolveUserIdentityCommand(
                self::TELEGRAM_PROVIDER,
                $telegramUserId->value(),
                $resolvedAt,
            ));
        } catch (CoreConcurrencyException $exception) {
            throw new UserIdentityResolutionConcurrencyException(
                'user_identity_resolution_concurrency_conflict',
                0,
                $exception,
            );
        } catch (CoreIntegrityException $exception) {
            throw new UserIdentityResolutionIntegrityException(
                'user_identity_resolution_integrity_failure',
                0,
                $exception,
            );
        } catch (CorePersistenceException $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }

        $userStatus = match ($resolvedIdentity->userStatus) {
            UserStatus::STATUS_ACTIVE => UserAccountStatus::ACTIVE,
            UserStatus::STATUS_INACTIVE => UserAccountStatus::INACTIVE,
            default => throw new UserIdentityResolutionIntegrityException(
                'user_identity_resolution_integrity_failure',
            ),
        };

        return new ResolvedUserIdentityContext(
            $resolvedIdentity->userId,
            $resolvedIdentity->userIdentityId,
            $userStatus,
            $resolvedIdentity->created,
        );
    }
}
