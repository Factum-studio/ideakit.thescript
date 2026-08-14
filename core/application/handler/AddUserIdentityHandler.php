<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\AddUserIdentityCommand;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\domain\entity\UserIdentity;
use core\domain\exception\IdentityAlreadyExistsException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;

class AddUserIdentityHandler
{
    private IUserIdentityRepository $identityRepository;
    private IUserRepository $userRepository;

    public function __construct(
        IUserIdentityRepository $identityRepository,
        IUserRepository $userRepository,
    ) {
        $this->identityRepository   = $identityRepository;
        $this->userRepository       = $userRepository;
    }

    /**
     * @throws UserNotFoundException
     * @throws IdentityAlreadyExistsException
     */
    public function handle(AddUserIdentityCommand $command): void
    {
        $userId = new UserId($command->userId);
        $user   = $this->userRepository->findById($userId);
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        // Проверяем, существует ли уже такая identity
        $existing = $this->identityRepository->findByProviderAndClientId(
            $command->provider,
            $command->providerClientId,
        );
        if ($existing) {
            throw new IdentityAlreadyExistsException(
                "Identity for provider '{$command->provider}' and client ID '{$command->providerClientId}' already exists",
            );
        }

        $identity = new UserIdentity(
            UserIdentityId::generate(),
            $userId,
            $command->provider,
            $command->providerClientId,
            new DateTimeImmutable(),
        );

        $this->identityRepository->save($identity);
        $user->addIdentity($identity);
    }
}
