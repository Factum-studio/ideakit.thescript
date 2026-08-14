<?php

declare(strict_types=1);

namespace core\application\handler;

use core\application\command\CreateUserCommand;
use core\application\port\ISecurityService;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use Yii;
use yii\base\Exception;

class CreateUserHandler
{
    private IUserRepository $userRepository;
    private ISecurityService $securityService;

    public function __construct(
        IUserRepository $userRepository,
        ISecurityService $securityService,
    ) {
        $this->userRepository   = $userRepository;
        $this->securityService  = $securityService;
    }

    /**
     * @throws UserAlreadyExistsException
     */
    public function handle(CreateUserCommand $command): User
    {
        // Проверка уникальности email
        if ($command->email) {
            $existing = $this->userRepository->findByEmail(new Email($command->email));
            if ($existing) {
                throw new UserAlreadyExistsException("User with email '{$command->email}' already exists");
            }
        }

        // Проверка уникальности phone
        if ($command->phone) {
            $existing = $this->userRepository->findByPhone(new Phone($command->phone));
            if ($existing) {
                throw new UserAlreadyExistsException("User with phone '{$command->phone}' already exists");
            }
        }

        $id     = UserId::generate();
        $authKey = $this->securityService->generateRandomString(32);
        $now    = new DateTimeImmutable();

        $user = new User(
            $id,
            $command->surname,
            $command->name,
            $command->patronymic,
            $command->email ? new Email($command->email) : null,
            $command->phone ? new Phone($command->phone) : null,
            new Role($command->role),
            $command->post,
            new UserStatus($command->status),
            $authKey,
            $now,
            $now,
            null
        );

        $this->userRepository->save($user);
        return $user;
    }
}
