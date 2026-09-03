<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\query\GetUserQuery;
use core\application\handler\GetUserHandler;
use core\application\port\IUserRepository;
use core\application\port\IUserIdentityRepository;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\domain\valueObject\UserIdentityId;
use core\application\dto\UserDto;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class GetUserHandlerTest extends Unit
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
     * @throws UserNotFoundException
     */
    public function testHandleSuccessWithoutExpand(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());

        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $identityRepo->expects($this->never())
            ->method('findByUserId');

        $handler = new GetUserHandler($userRepo, $identityRepo);
        $query = new GetUserQuery($userId->value());
        $dto = $handler->handle($query);

        $this->assertInstanceOf(UserDto::class, $dto);

        $json = $dto->jsonSerialize();
        $this->assertEquals($userId->value(), $json['id']);
        $this->assertEquals('Doe', $json['surname']);
        $this->assertEquals('John', $json['name']);
        $this->assertEquals('Michael', $json['patronymic']);
        $this->assertEquals('john@example.com', $json['email']);
        $this->assertEquals('+1234567890', $json['phone']);
        $this->assertEquals('user', $json['role']);
        $this->assertEquals('Developer', $json['post']);
        $this->assertEquals(1, $json['status']);
        $this->assertArrayNotHasKey('identities', $json);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function testHandleWithExpandIdentities(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());
        $identities = [
            $this->createIdentity($userId->value(), 'google', 'client123'),
            $this->createIdentity($userId->value(), 'facebook', 'fb456'),
        ];

        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $identityRepo->expects($this->once())
            ->method('findByUserId')
            ->with($userId)
            ->willReturn($identities);

        $handler = new GetUserHandler($userRepo, $identityRepo);
        $query = new GetUserQuery($userId->value(), ['identities']);
        $dto = $handler->handle($query);

        $this->assertInstanceOf(UserDto::class, $dto);

        $json = $dto->jsonSerialize();
        $this->assertEquals($userId->value(), $json['id']);
        $this->assertArrayHasKey('identities', $json);
        $this->assertCount(2, $json['identities']);
        $this->assertEquals('google', $json['identities'][0]['provider']);
        $this->assertEquals('client123', $json['identities'][0]['provider_client_id']);
        $this->assertEquals('facebook', $json['identities'][1]['provider']);
        $this->assertEquals('fb456', $json['identities'][1]['provider_client_id']);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function testHandleWithExpandOtherIgnored(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value());

        $userRepo = $this->createMock(IUserRepository::class);
        $identityRepo = $this->createMock(IUserIdentityRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $identityRepo->expects($this->never())
            ->method('findByUserId');

        $handler = new GetUserHandler($userRepo, $identityRepo);
        $query = new GetUserQuery($userId->value(), ['something', 'else']);
        $dto = $handler->handle($query);

        $this->assertInstanceOf(UserDto::class, $dto);
        $json = $dto->jsonSerialize();
        $this->assertEquals($userId->value(), $json['id']);
        $this->assertArrayNotHasKey('identities', $json);
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

        $handler = new GetUserHandler($userRepo, $identityRepo);
        $query = new GetUserQuery($nonExistingId);
        $handler->handle($query);
    }
}
