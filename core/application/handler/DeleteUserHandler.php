<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\DeleteUserCommand;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;

class DeleteUserHandler
{
    private IUserRepository $userRepository;
    private IUserIdentityRepository $identityRepository;

    public function __construct(
        IUserRepository $userRepository,
        IUserIdentityRepository $identityRepository,
    ) {
        $this->userRepository       = $userRepository;
        $this->identityRepository   = $identityRepository;
    }

    /**
     * @throws UserNotFoundException
     */
    public function handle(DeleteUserCommand $command): void
    {
        $userId = new UserId($command->userId);
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }
        $this->identityRepository->deleteByUserId($userId);
        $this->userRepository->delete($userId);
    }
}
