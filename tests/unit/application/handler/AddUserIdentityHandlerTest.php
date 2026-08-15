<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\AddUserIdentityCommand;
use core\application\handler\AddUserIdentityHandler;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\exception\IdentityAlreadyExistsException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class AddUserIdentityHandlerTest extends Unit
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
     * @throws Exception
     * @throws UserNotFoundException
     * @throws IdentityAlreadyExistsException
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
            ->method('findByProviderAndClientId')
            ->with('google', 'client123')
            ->willReturn(null);

        $identityRepo->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($identity) use ($userId) {
                return $identity instanceof UserIdentity
                    && $identity->getUserId()->value() === $userId->value()
                    && $identity->getProvider() === 'google'
                    && $identity->getProviderClientId() === 'client123';
            }));

        $userRepo->expects($this->never())
            ->method('save');

        $handler = new AddUserIdentityHandler($identityRepo, $userRepo);
        $command = new AddUserIdentityCommand($userId->value(), 'google', 'client123');
        $handler->handle($command);

        // Проверяем, что identity добавлена пользователю
        $identities = $user->getIdentities();
        $this->assertCount(1, $identities);
        $this->assertEquals('google', $identities[0]->getProvider());
        $this->assertEquals('client123', $identities[0]->getProviderClientId());
    }

    /**
     * @throws Exception
     * @throws IdentityAlreadyExistsException
     */
    public function testHandleUserNotFoundThrowsException(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $nonExistingId = Uuid::uuid4()->toString(); // валидный UUID

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($this->callback(fn($id) => $id->value() === $nonExistingId))
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("User with ID {$nonExistingId} not found");

        $handler = new AddUserIdentityHandler($identityRepo, $userRepo);
        $command = new AddUserIdentityCommand($nonExistingId, 'google', 'client123');
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function testHandleIdentityAlreadyExistsThrowsException(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());

        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->willReturn($user);

        $existingIdentity = $this->createMock(UserIdentity::class);
        $identityRepo->expects($this->once())
            ->method('findByProviderAndClientId')
            ->with('google', 'client123')
            ->willReturn($existingIdentity);

        $identityRepo->expects($this->never())
            ->method('save');

        $userRepo->expects($this->never())
            ->method('save');

        $this->expectException(IdentityAlreadyExistsException::class);
        $this->expectExceptionMessage("Identity for provider 'google' and client ID 'client123' already exists");

        $handler = new AddUserIdentityHandler($identityRepo, $userRepo);
        $command = new AddUserIdentityCommand($userId->value(), 'google', 'client123');
        $handler->handle($command);
    }
}
