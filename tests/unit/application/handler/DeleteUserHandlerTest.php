<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\DeleteUserCommand;
use core\application\handler\DeleteUserHandler;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\domain\entity\User;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class DeleteUserHandlerTest extends Unit
{
    private function createUser(string $id): User
    {
        return new User(
            new UserId($id),
            'Doe',
            'John',
            null,
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role('user'),
            'Developer',
            new UserStatus(1),
            'authKey',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            null
        );
    }

    /**
     * @throws UserNotFoundException
     * @throws Exception
     */
    public function testHandleSuccess(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());

        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $identityRepo->expects($this->once())
            ->method('deleteByUserId')
            ->with($userId);

        $userRepo->expects($this->once())
            ->method('delete')
            ->with($userId);

        $userRepo->expects($this->never())
            ->method('save');

        $handler = new DeleteUserHandler($userRepo, $identityRepo);
        $command = new DeleteUserCommand($userId->value());
        $handler->handle($command);

        // Если метод не выбросил исключение, тест пройден
        $this->assertTrue(true);
    }

    /**
     * @throws Exception
     */
    public function testHandleUserNotFoundThrowsException(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $nonExistingId = Uuid::uuid4()->toString();

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($this->callback(fn($id) => $id->value() === $nonExistingId))
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("User with ID {$nonExistingId} not found");

        $handler = new DeleteUserHandler($userRepo, $identityRepo);
        $command = new DeleteUserCommand($nonExistingId);
        $handler->handle($command);
    }
}
