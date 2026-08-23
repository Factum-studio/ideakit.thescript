<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\ChangeUserStatusCommand;
use core\application\handler\ChangeUserStatusHandler;
use core\application\port\IUserRepository;
use core\domain\entity\User;
use core\domain\exception\PermissionDeniedException;
use core\domain\exception\UserNotFoundException;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class ChangeUserStatusHandlerTest extends Unit
{
    private function createUser(string $id, string $role = 'user', int $status = 1): User
    {
        return new User(
            new UserId($id),
            'Doe',
            'John',
            null,
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role($role),
            'Developer',
            new UserStatus($status),
            'authKey',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            null
        );
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     * @throws InvalidArgumentException
     */
    public function testHandleSuccess(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user', 1);

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
            ->method('save')
            ->with($target);

        $handler = new ChangeUserStatusHandler($userRepo);
        $command = new ChangeUserStatusCommand(
            userId: $targetId->value(),
            newStatus: 0,
            updatedBy: $actorId->value()
        );
        $handler->handle($command);

        $this->assertEquals(0, $target->getStatus()->value());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws InvalidArgumentException
     */
    public function testHandlePermissionDenied(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'user'); // не админ

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($actorId)
            ->willReturn($actor);

        $this->expectException(PermissionDeniedException::class);
        $this->expectExceptionMessage('Only administrators can change user status.');

        $handler = new ChangeUserStatusHandler($userRepo);
        $command = new ChangeUserStatusCommand(
            userId: $targetId->value(),
            newStatus: 0,
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws PermissionDeniedException
     * @throws InvalidArgumentException
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

        $handler = new ChangeUserStatusHandler($userRepo);
        $command = new ChangeUserStatusCommand(
            userId: $targetId->value(),
            newStatus: 0,
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws PermissionDeniedException
     * @throws InvalidArgumentException
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

        $handler = new ChangeUserStatusHandler($userRepo);
        $command = new ChangeUserStatusCommand(
            userId: $targetId->value(),
            newStatus: 0,
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function testHandleInvalidStatus(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');

        $userRepo = $this->createMock(IUserRepository::class);

        $userRepo->expects($this->exactly(2))
            ->method('findById')
            ->willReturnCallback(function ($id) use ($actorId, $actor) {
                if ($id->value() === $actorId->value()) {
                    return $actor;
                }
                return $this->createUser($id->value(), 'user');
            });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user status');

        $handler = new ChangeUserStatusHandler($userRepo);
        $command = new ChangeUserStatusCommand(
            userId: $targetId->value(),
            newStatus: 99, // невалидный статус
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }
}
