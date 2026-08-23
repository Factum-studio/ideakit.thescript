<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\UpdateUserCommand;
use core\application\port\IUserRepository;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;

class UpdateUserHandler
{
    private IUserRepository $userRepository;

    public function __construct(IUserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function handle(UpdateUserCommand $command): void
    {
        // 1. Найти пользователя, который выполняет действие
        $actor = $this->userRepository->findById(new UserId($command->updatedBy));
        if (!$actor) {
            throw new UserNotFoundException("Actor with ID {$command->updatedBy} not found");
        }

        // 2. Проверить права: администратор может редактировать любого, обычный пользователь - только себя
        $isAdmin    = $actor->getRole()->isAdmin();
        $isSelf     = $command->updatedBy === $command->userId;

        if (!$isAdmin && !$isSelf) {
            throw new PermissionDeniedException('You can only edit your own profile.');
        }

        // 3. Найти целевого пользователя
        $user = $this->userRepository->findById(new UserId($command->userId));
        if (!$user) {
            throw new UserNotFoundException("User with ID {$command->userId} not found");
        }

        // 4. Проверка уникальности email, если он меняется
        if ($command->email !== null) {
            $email  = new Email($command->email);
            $existing = $this->userRepository->findByEmail($email);
            if ($existing && !$existing->getId()->equals($user->getId())) {
                throw new UserAlreadyExistsException("Email '{$command->email}' is already taken by another user.");
            }
        }

        // 5. Проверка уникальности phone, если он меняется
        if ($command->phone !== null) {
            $phone  = new Phone($command->phone);
            $existing = $this->userRepository->findByPhone($phone);
            if ($existing && !$existing->getId()->equals($user->getId())) {
                throw new UserAlreadyExistsException("Phone '{$command->phone}' is already taken by another user.");
            }
        }

        $surname    = $command->surname !== null ? $command->surname : $user->getSurname();
        $name       = $command->name !== null ? $command->name : $user->getName();
        $patronymic = $command->patronymic !== null ? $command->patronymic : $user->getPatronymic();
        $email      = $command->email !== null ? new Email($command->email) : $user->getEmail();
        $phone      = $command->phone !== null ? new Phone($command->phone) : $user->getPhone();
        $post       = $user->getPost(); // не обновляется

        $user->updateProfile($surname, $name, $patronymic, $email, $phone, $post);

        $this->userRepository->save($user);
    }
}
