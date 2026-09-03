<?php

declare(strict_types=1);

namespace core\application\useCase;

use core\application\command\AddUserIdentityCommand;
use core\application\command\CreateUserCommand;
use core\application\dto\AuthRequestDto;
use core\application\dto\AuthResponseDto;
use core\application\dto\UserDto;
use core\application\handler\AddUserIdentityHandler;
use core\application\handler\CreateUserHandler;
use core\application\port\IJwtManager;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\domain\exception\IdentityAlreadyExistsException;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\exception\UserNotFoundException;

class AuthenticateUseCase
{
    private IUserRepository $userRepository;
    private IUserIdentityRepository $identityRepository;
    private IJwtManager $jwtManager;
    private CreateUserHandler $createUserHandler;
    private AddUserIdentityHandler $addIdentityHandler;

    public function __construct(
        IUserRepository $userRepository,
        IUserIdentityRepository $identityRepository,
        IJwtManager $jwtManager,
        CreateUserHandler $createUserHandler,
        AddUserIdentityHandler $addIdentityHandler,
    ) {
        $this->userRepository       = $userRepository;
        $this->identityRepository   = $identityRepository;
        $this->jwtManager           = $jwtManager;
        $this->createUserHandler    = $createUserHandler;
        $this->addIdentityHandler   = $addIdentityHandler;
    }

    /**
     * @throws UserAlreadyExistsException
     * @throws IdentityAlreadyExistsException
     * @throws UserNotFoundException
     */
    public function execute(AuthRequestDto $request): AuthResponseDto
    {
        // 1. Ищем identity по провайдеру и client_id
        $identity = $this->identityRepository->findByProviderAndClientId(
            $request->provider,
            $request->providerClientId,
        );

        $user = null;
        if ($identity) {
            $user = $this->userRepository->findById($identity->getUserId());
        }

        // 2. Если пользователь не найден, создаём нового
        if (!$user) {
            $createCommand = new CreateUserCommand(
                surname: $request->userData['surname'] ?? 'Unknown',
                name: $request->userData['name'] ?? 'User',
                patronymic: $request->userData['patronymic'] ?? null,
                email: $request->userData['email'] ?? null,
                phone: $request->userData['phone'] ?? null,
                role: 'user',
                post: $request->userData['post'] ?? null,
                status: 1,
            );
            $user = $this->createUserHandler->handle($createCommand);
        }

        // 3. Добавляем identity, если её ещё нет
        $existingIdentity = $this->identityRepository->findByProviderAndClientId(
            $request->provider,
            $request->providerClientId,
        );
        if (!$existingIdentity) {
            $addCommand = new AddUserIdentityCommand(
                userId: $user->getId()->value(),
                provider: $request->provider,
                providerClientId: $request->providerClientId,
            );
            $this->addIdentityHandler->handle($addCommand);
        }

        // 4. Обновляем время последнего входа
        $user->updateLastLogin();
        $this->userRepository->save($user);

        // 5. Генерируем JWT
        $token = $this->jwtManager->generate($user);

        // 6. Формируем ответ
        $userDto = new UserDto($user);
        return new AuthResponseDto($token, $userDto->jsonSerialize());
    }
}
