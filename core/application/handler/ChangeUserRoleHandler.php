<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\ChangeUserRoleCommand;
use core\application\port\IUserRepository;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserId;
use InvalidArgumentException;

class ChangeUserRoleHandler
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
    public function handle(ChangeUserRoleCommand $command): void
    {
        // 1. Найти пользователя, который выполняет действие
        $actor = $this->userRepository->findById(new UserId($command->updatedBy));
        if (!$actor) {
            throw new UserNotFoundException("User with ID {$command->updatedBy} not found");
        }

        // 2. Проверить, что актор имеет роль администратора
        if ($actor->getRole()->value() !== 'admin') {
            throw new PermissionDeniedException('Only administrators can change user roles.');
        }

        // 3. Найти целевого пользователя
        $targetUser = $this->userRepository->findById(new UserId($command->userId));
        if (!$targetUser) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        // 4. Изменить роль
        $newRole = new Role($command->newRole);
        $targetUser->changeRole($newRole);
        $this->userRepository->save($targetUser);
    }
}
