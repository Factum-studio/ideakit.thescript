<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\ChangeUserStatusCommand;
use core\application\port\IUserRepository;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserStatus;
use InvalidArgumentException;

class ChangeUserStatusHandler
{
    private IUserRepository $userRepository;

    public function __construct(IUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     * @throws InvalidArgumentException
     */
    public function handle(ChangeUserStatusCommand $command): void
    {
        // Проверка прав: только администратор может менять статус
        $actor = $this->userRepository->findById(new UserId($command->updatedBy));
        if (!$actor) {
            throw new UserNotFoundException("Actor with ID {$command->updatedBy} not found");
        }
        if (!$actor->getRole()->isAdmin()) {
            throw new PermissionDeniedException('Only administrators can change user status.');
        }

        $user = $this->userRepository->findById(new UserId($command->userId));
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        $newStatus = new UserStatus($command->newStatus);
        $user->changeStatus($newStatus);
        $this->userRepository->save($user);
    }
}
