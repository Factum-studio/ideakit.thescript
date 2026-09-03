<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\query\GetUserByIdentityQuery;
use core\application\handler\GetUserByIdentityHandler;
use core\application\port\IUserIdentityRepository;
use core\application\port\IUserRepository;
use core\domain\entity\UserIdentity;
use core\domain\entity\User;
use core\domain\exception\IdentityNotFoundException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\UserIdentityId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\application\dto\UserDto;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;

class GetUserByIdentityHandlerTest extends Unit
{
    private function createUser(string $id): User
    {
        return new User(
            new UserId($id),
            'Doe',
            'John',
            'Michael',
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role('user'),
            'Developer',
            new UserStatus(1),
            'authKey',
            new DateTimeImmutable('2023-01-01 00:00:00'),
            new DateTimeImmutable('2023-01-01 00:00:00'),
            null
        );
    }

    private function createIdentity(string $userId, string $provider = 'google', string $clientId = 'client123'): UserIdentity
    {
        return new UserIdentity(
            UserIdentityId::generate(),
            new UserId($userId),
            $provider,
            $clientId,
            new DateTimeImmutable()
        );
    }

    /**
     * @throws Exception
     * @throws IdentityNotFoundException
     * @throws UserNotFoundException
     */
    public function testHandleSuccess(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());
        $identity = $this->createIdentity($userId->value(), 'google', 'client123');

        $identityRepo = $this->createMock(IUserIdentityRepository::class);
        $userRepo = $this->createMock(IUserRepository::class);

        $identityRepo->expects($this->once())
            ->method('findByProviderAndClientId')
            ->with('google', 'client123')
            ->willReturn($identity);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $handler = new GetUserByIdentityHandler($identityRepo, $userRepo);
        $query = new GetUserByIdentityQuery('google', 'client123');
        $dto = $handler->handle($query);

        $this->assertInstanceOf(UserDto::class, $dto);

        // Проверяем содержимое DTO
        $json = $dto->jsonSerialize();
        $this->assertEquals($userId->value(), $json['id']);
        $this->assertEquals('Doe', $json['surname']);
        $this->assertEquals('John', $json['name']);
        $this->assertEquals('john@example.com', $json['email']);
        $this->assertEquals('user', $json['role']);
        $this->assertArrayNotHasKey('identities', $json);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function testHandleIdentityNotFoundThrowsException(): void
    {
        $identityRepo = $this->createMock(IUserIdentityRepository::class);
        $userRepo = $this->createMock(IUserRepository::class);

        $identityRepo->expects($this->once())
            ->method('findByProviderAndClientId')
            ->with('google', 'client123')
            ->willReturn(null);

        $this->expectException(IdentityNotFoundException::class);
        $this->expectExceptionMessage("Identity not found for provider 'google' and client ID 'client123'");

        $handler = new GetUserByIdentityHandler($identityRepo, $userRepo);
        $query = new GetUserByIdentityQuery('google', 'client123');
        $handler->handle($query);
    }

    /**
     * @throws IdentityNotFoundException
     * @throws Exception
     */
    public function testHandleUserNotFoundThrowsException(): void
    {
        $userId = UserId::generate();
        $identity = $this->createIdentity($userId->value(), 'google', 'client123');

        $identityRepo = $this->createMock(IUserIdentityRepository::class);
        $userRepo = $this->createMock(IUserRepository::class);

        $identityRepo->expects($this->once())
            ->method('findByProviderAndClientId')
            ->with('google', 'client123')
            ->willReturn($identity);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User for identity not found');

        $handler = new GetUserByIdentityHandler($identityRepo, $userRepo);
        $query = new GetUserByIdentityQuery('google', 'client123');
        $handler->handle($query);
    }
}
