<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\UpdateUserCommand;
use core\application\port\IUserRepository;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;

class UpdateUserHandler
{
    private IUserRepository $userRepository;

    public function __construct(IUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @throws UserNotFoundException
     */
    public function handle(UpdateUserCommand $command): void
    {
        $user = $this->userRepository->findById(new UserId($command->userId));
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        $surname    = $command->surname ?? $user->getSurname();
        $name       = $command->name ?? $user->getName();
        $patronymic = $command->patronymic ?? $user->getPatronymic();
        $email      = $command->email !== null ? new Email($command->email) : $user->getEmail();
        $phone      = $command->phone !== null ? new Phone($command->phone) : $user->getPhone();
        $post       = $command->post ?? $user->getPost();

        $user->updateProfile($surname, $name, $patronymic, $email, $phone, $post);

        if ($command->role !== null) {
            $user->changeRole(new Role($command->role));
        }
        if ($command->status !== null) {
            $user->changeStatus(new UserStatus($command->status));
        }

        $this->userRepository->save($user);
    }
}
