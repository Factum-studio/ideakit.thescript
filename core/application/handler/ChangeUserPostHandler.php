<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\ChangeUserPostCommand;
use core\application\port\IUserRepository;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;

class ChangeUserPostHandler
{
    private IUserRepository $userRepository;

    public function __construct(IUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function handle(ChangeUserPostCommand $command): void
    {
        // Проверка прав: только администратор может менять должность
        $actor = $this->userRepository->findById(new UserId($command->updatedBy));
        if (!$actor) {
            throw new UserNotFoundException("Actor with ID {$command->updatedBy} not found");
        }
        if (!$actor->getRole()->isAdmin()) {
            throw new PermissionDeniedException('Only administrators can change user post.');
        }

        $user = $this->userRepository->findById(new UserId($command->userId));
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        // Обновляем должность (если null - устанавливаем null)
        $user->updatePost($command->newPost);
        $this->userRepository->save($user);
    }
}
