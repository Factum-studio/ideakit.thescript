<?php

namespace unit\application\useCase;

use Codeception\Test\Unit;
use core\application\useCase\AuthenticateUseCase;
use core\application\dto\AuthRequestDto;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\application\port\ISecurityService;
use core\application\handler\CreateUserHandler;
use core\application\handler\AddUserIdentityHandler;
use core\domain\exception\IdentityAlreadyExistsException;
use core\domain\exception\UserNotFoundException;
use core\infrastructure\jwt\JwtManager;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\valueObject\UserId;
use PHPUnit\Framework\MockObject\Exception;

class AuthenticateUseCaseTest extends Unit
{
    /**
     * @throws UserNotFoundException
     * @throws IdentityAlreadyExistsException
     * @throws UserAlreadyExistsException
     * @throws Exception
     */
    public function testExecuteWithExistingUserAndIdentity()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);
        $jwtManager = $this->createMock(JwtManager::class);
        $createHandler = $this->createMock(CreateUserHandler::class);
        $addHandler = $this->createMock(AddUserIdentityHandler::class);
        $security = $this->createMock(ISecurityService::class);

        $userId = UserId::generate();
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn(new \core\domain\valueObject\Role('user'));
        $user->method('getAuthKey')->willReturn('authKey');

        $identity = $this->createMock(UserIdentity::class);
        $identity->method('getUserId')->willReturn($userId);

        $identityRepo->expects($this->exactly(2))
            ->method('findByProviderAndClientId')
            ->willReturn($identity);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $userRepo->expects($this->once())
            ->method('save')
            ->with($user);

        $jwtManager->expects($this->once())
            ->method('generate')
            ->with($user)
            ->willReturn('jwt.token');

        $useCase = new AuthenticateUseCase(
            $userRepo, $identityRepo, $jwtManager,
            $createHandler, $addHandler, $security
        );

        $request = new AuthRequestDto('google', 'client123', ['email' => 'john@example.com']);
        $response = $useCase->execute($request);

        $this->assertEquals('jwt.token', $response->accessToken);
        $this->assertIsArray($response->user);
    }

    /**
     * @throws UserNotFoundException
     * @throws IdentityAlreadyExistsException
     * @throws UserAlreadyExistsException
     * @throws Exception
     */
    public function testExecuteWithNewUser()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);
        $jwtManager = $this->createMock(JwtManager::class);
        $createHandler = $this->createMock(CreateUserHandler::class);
        $addHandler = $this->createMock(AddUserIdentityHandler::class);
        $security = $this->createMock(ISecurityService::class);

        $userId = UserId::generate();
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($userId);
        $user->method('getRole')->willReturn(new \core\domain\valueObject\Role('user'));
        $user->method('getAuthKey')->willReturn('authKey');

        $identityRepo->expects($this->exactly(2))
            ->method('findByProviderAndClientId')
            ->willReturn(null);

        $createHandler->expects($this->once())
            ->method('handle')
            ->willReturn($user);

        $addHandler->expects($this->once())
            ->method('handle');

        $userRepo->expects($this->once())
            ->method('save')
            ->with($user);

        $jwtManager->expects($this->once())
            ->method('generate')
            ->willReturn('jwt.token');

        $useCase = new AuthenticateUseCase(
            $userRepo, $identityRepo, $jwtManager,
            $createHandler, $addHandler, $security
        );

        $request = new AuthRequestDto('google', 'client123', ['email' => 'john@example.com']);
        $response = $useCase->execute($request);

        $this->assertEquals('jwt.token', $response->accessToken);
    }
}
