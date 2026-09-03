<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\UpdateUserCommand;
use core\application\handler\UpdateUserHandler;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserAlreadyExistsException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class UpdateUserHandlerTest extends Unit
{
    private function createUser(string $id, string $role = 'user'): User
    {
        return new User(
            new UserId($id),
            'Doe',
            'John',
            'Michael',
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role($role),
            'Developer',
            new UserStatus(1),
            'authKey',
            new DateTimeImmutable('2023-01-01 00:00:00'),
            new DateTimeImmutable('2023-01-01 00:00:00'),
            null
        );
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandleSuccessAsAdmin(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId, $target) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return $target;
                }
                return null;
            });

        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);
        $userRepo->expects($this->once())
            ->method('findByPhone')
            ->willReturn(null);

        $userRepo->expects($this->once())
            ->method('save')
            ->with($target);

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            surname: 'Smith',
            name: 'Jane',
            email: 'jane@example.com',
            phone: '+9876543210'
        );
        $handler->handle($command);

        $this->assertEquals('Smith', $target->getSurname());
        $this->assertEquals('Jane', $target->getName());
        $this->assertEquals('Michael', $target->getPatronymic());
        $this->assertEquals('jane@example.com', $target->getEmail()->value());
        $this->assertEquals('+9876543210', $target->getPhone()->value());
        $this->assertEquals('Developer', $target->getPost());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandleSuccessAsSelf(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        // Ожидаем два вызова findById – оба для одного и того же пользователя
        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        // Поскольку в команде email и phone не передаются, вызовы findByEmail/findByPhone не происходят
        $userRepo->expects($this->never())
            ->method('findByEmail');
        $userRepo->expects($this->never())
            ->method('findByPhone');

        $userRepo->expects($this->once())
            ->method('save')
            ->with($user);

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $userId->value(),
            updatedBy: $userId->value(),
            surname: 'Self',
            name: 'Updater'
        );
        $handler->handle($command);

        $this->assertEquals('Self', $user->getSurname());
        $this->assertEquals('Updater', $user->getName());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     */
    public function testHandlePermissionDenied(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'user'); // не админ, не self
        $target = $this->createUser($targetId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        // Ожидаем только один вызов findById – для актора
        $userRepo->expects($this->once())
            ->method('findById')
            ->with($actorId)
            ->willReturn($actor);

        $this->expectException(PermissionDeniedException::class);
        $this->expectExceptionMessage('You can only edit your own profile.');

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            surname: 'Hack'
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandleActorNotFound(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($actorId)
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("Actor with ID {$actorId->value()} not found");

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandleTargetNotFound(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return null;
                }
                return null;
            });

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("User with ID {$targetId->value()} not found");

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function testHandleEmailAlreadyExists(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();
        $otherUserId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user');
        $otherUser = $this->createUser($otherUserId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId, $target) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return $target;
                }
                return null;
            });

        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn($otherUser);

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage("Email 'john@example.com' is already taken by another user.");

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            email: 'john@example.com'
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function testHandlePhoneAlreadyExists(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();
        $otherUserId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user');
        $otherUser = $this->createUser($otherUserId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId, $target) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return $target;
                }
                return null;
            });

        $userRepo->expects($this->once())
            ->method('findByPhone')
            ->willReturn($otherUser);

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage("Phone '+1234567890' is already taken by another user.");

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            phone: '+1234567890'
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandlePartialUpdate(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId, $target) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return $target;
                }
                return null;
            });

        // Поскольку в команде не переданы email и phone, вызовы findByEmail/findByPhone не будут выполнены
        $userRepo->expects($this->never())
            ->method('findByEmail');
        $userRepo->expects($this->never())
            ->method('findByPhone');

        $userRepo->expects($this->once())
            ->method('save')
            ->with($target);

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            surname: 'NewSurname'
        );
        $handler->handle($command);

        $this->assertEquals('NewSurname', $target->getSurname());
        $this->assertEquals('John', $target->getName());
        $this->assertEquals('Michael', $target->getPatronymic());
        $this->assertEquals('john@example.com', $target->getEmail()->value());
        $this->assertEquals('+1234567890', $target->getPhone()->value());
        $this->assertEquals('Developer', $target->getPost());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws UserAlreadyExistsException
     * @throws PermissionDeniedException
     */
    public function testHandleUpdateWithSameEmailAndPhone(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor, $targetId, $target) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                if ($id->value() === $targetId->value()) {
                    return $target;
                }
                return null;
            });

        // findByEmail возвращает того же пользователя (себя) – конфликта нет
        $userRepo->expects($this->once())
            ->method('findByEmail')
            ->willReturn($target);
        $userRepo->expects($this->once())
            ->method('findByPhone')
            ->willReturn($target);

        $userRepo->expects($this->once())
            ->method('save')
            ->with($target);

        $handler = new UpdateUserHandler($userRepo);
        $command = new UpdateUserCommand(
            userId: $targetId->value(),
            updatedBy: $actorId->value(),
            email: 'john@example.com',
            phone: '+1234567890'
        );
        $handler->handle($command);

        $this->assertEquals('john@example.com', $target->getEmail()->value());
        $this->assertEquals('+1234567890', $target->getPhone()->value());
    }
}
