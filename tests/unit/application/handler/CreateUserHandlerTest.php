<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\CreateUserCommand;
use core\application\handler\CreateUserHandler;
use core\application\port\IUserRepository;
use core\application\port\ISecurityService;
use core\domain\entity\User;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use PHPUnit\Framework\MockObject\Exception;

class CreateUserHandlerTest extends Unit
{
    /**
     * @throws UserAlreadyExistsException
     * @throws Exception
     */
    public function testHandleSuccess()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);
        $userRepo->expects($this->once())
            ->method('findByPhone')
            ->willReturn(null);

        $security->expects($this->once())
            ->method('generateRandomString')
            ->with(32)
            ->willReturn('randomAuthKey');

        $userRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($user) {
                return $user instanceof User
                    && $user->getSurname() === 'Doe'
                    && $user->getName() === 'John'
                    && $user->getEmail()->value() === 'john@example.com'
                    && $user->getPhone()->value() === '+1234567890';
            }));

        $handler = new CreateUserHandler($userRepo, $security);
        $command = new CreateUserCommand(
            'Doe',
            'John',
            null,
            'john@example.com',
            '+1234567890',
            'user',
            'Developer',
            1
        );
        $user = $handler->handle($command);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('randomAuthKey', $user->getAuthKey());
    }

    /**
     * @throws Exception
     */
    public function testHandleEmailAlreadyExistsThrowsException()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn($this->createMock(User::class));

        $this->expectException(UserAlreadyExistsException::class);

        $handler = new CreateUserHandler($userRepo, $security);
        $command = new CreateUserCommand('Doe', 'John', null, 'john@example.com', null);
        $handler->handle($command);
    }

    /**
     * @throws Exception
     */
    public function testHandlePhoneAlreadyExistsThrowsException()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);
        $userRepo->expects($this->once())
            ->method('findByPhone')
            ->willReturn($this->createMock(User::class));

        $this->expectException(UserAlreadyExistsException::class);

        $handler = new CreateUserHandler($userRepo, $security);
        $command = new CreateUserCommand('Doe', 'John', null, 'john@example.com', '+1234567890');
        $handler->handle($command);
    }

    /**
     * @throws UserAlreadyExistsException
     * @throws Exception
     */
    public function testHandleSuccessWithoutEmailAndPhone()
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $userRepo->expects($this->never())->method('findByEmail');
        $userRepo->expects($this->never())->method('findByPhone');

        $security->expects($this->once())->method('generateRandomString')->willReturn('authKey');
        $userRepo->expects($this->once())->method('save');

        $handler = new CreateUserHandler($userRepo, $security);
        $command = new CreateUserCommand('Doe', 'John', null, null, null, 'user', null, 1);
        $user = $handler->handle($command);
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getPhone());
    }
}
