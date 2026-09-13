<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\ResolveUserIdentityCommand;
use core\application\dto\ResolvedUserIdentity;
use core\application\exception\UserIdentityConcurrencyException;
use core\application\exception\UserIdentityOwnerNotFoundException;
use core\application\exception\UserIdentityResolutionPersistenceException;
use core\application\port\ISecurityService;
use core\application\port\ITransactionManager;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use core\domain\valueObject\UserStatus;
use Throwable;

final class ResolveUserIdentityHandler
{
    public function __construct(
        private readonly IUserIdentityRepository $identityRepository,
        private readonly IUserRepository $userRepository,
        private readonly ISecurityService $securityService,
        private readonly ITransactionManager $transactionManager,
    ) {
    }

    public function handle(ResolveUserIdentityCommand $command): ResolvedUserIdentity
    {
        try {
            return $this->transactionManager->transactional(
                function (ITransactionManager $_transactionManager) use ($command): ResolvedUserIdentity {
                    $identity = $this->identityRepository->findByProviderAndClientId(
                        $command->provider,
                        $command->providerClientId,
                    );

                    if ($identity !== null) {
                        $user = $this->userRepository->findById($identity->getUserId());
                        if ($user === null) {
                            throw new UserIdentityOwnerNotFoundException(
                                'user_identity_owner_not_found',
                            );
                        }

                        return new ResolvedUserIdentity(
                            $user->getId()->value(),
                            $identity->getId()->value(),
                            $user->getStatus()->value(),
                            false,
                        );
                    }

                    $userId = UserId::generate();
                    $user = new User(
                        $userId,
                        null,
                        null,
                        null,
                        null,
                        null,
                        new Role(Role::ROLE_USER),
                        null,
                        new UserStatus(UserStatus::STATUS_ACTIVE),
                        $this->securityService->generateRandomString(32),
                        $command->resolvedAt,
                        $command->resolvedAt,
                    );
                    $identity = new UserIdentity(
                        UserIdentityId::generate(),
                        $userId,
                        $command->provider,
                        $command->providerClientId,
                        $command->resolvedAt,
                    );

                    $this->userRepository->save($user);
                    $this->identityRepository->save($identity);

                    return new ResolvedUserIdentity(
                        $userId->value(),
                        $identity->getId()->value(),
                        UserStatus::STATUS_ACTIVE,
                        true,
                    );
                },
            );
        } catch (
            UserIdentityConcurrencyException
            | UserIdentityOwnerNotFoundException
            | UserIdentityResolutionPersistenceException $exception
        ) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UserIdentityResolutionPersistenceException(
                'user_identity_resolution_persistence_failure',
                0,
                $exception,
            );
        }
    }
}
