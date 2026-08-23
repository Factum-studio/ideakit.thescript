<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\ChangeUserPostCommand;
use core\application\handler\ChangeUserPostHandler;
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
use PHPUnit\Framework\MockObject\Exception;
use Ramsey\Uuid\Uuid;

class ChangeUserPostHandlerTest extends Unit
{
    private function createUser(string $id, string $role = 'user', ?string $post = 'Developer'): User
    {
        return new User(
            new UserId($id),
            'Doe',
            'John',
            null,
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role($role),
            $post,
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
     * @throws PermissionDeniedException
     */
    public function testHandleSuccess(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user', 'Developer');

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

        $handler = new ChangeUserPostHandler($userRepo);
        $command = new ChangeUserPostCommand(
            userId: $targetId->value(),
            newPost: 'Senior Developer',
            updatedBy: $actorId->value()
        );
        $handler->handle($command);

        $this->assertEquals('Senior Developer', $target->getPost());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function testHandleSuccessSetNull(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');
        $target = $this->createUser($targetId->value(), 'user', 'Developer');

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

        $handler = new ChangeUserPostHandler($userRepo);
        $command = new ChangeUserPostCommand(
            userId: $targetId->value(),
            newPost: null,
            updatedBy: $actorId->value()
        );
        $handler->handle($command);

        $this->assertNull($target->getPost());
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     * @throws PermissionDeniedException
     */
    public function testHandlePermissionDenied(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'user'); // не админ

        $userRepo = $this->createMock(IUserRepository::class);

        // Ожидаем только один вызов findById – для актора
        $userRepo->expects($this->once())
            ->method('findById')
            ->with($actorId)
            ->willReturn($actor);

        $this->expectException(PermissionDeniedException::class);
        $this->expectExceptionMessage('Only administrators can change user post.');

        $handler = new ChangeUserPostHandler($userRepo);
        $command = new ChangeUserPostCommand(
            userId: $targetId->value(),
            newPost: 'Manager',
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
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

        $handler = new ChangeUserPostHandler($userRepo);
        $command = new ChangeUserPostCommand(
            userId: $targetId->value(),
            newPost: 'Manager',
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }

    /**
     * @throws Exception
     * @throws PermissionDeniedException
     */
    public function testHandleTargetNotFound(): void
    {
        $actorId = UserId::generate();
        $targetId = UserId::generate();

        $actor = $this->createUser($actorId->value(), 'admin');

        $userRepo = $this->createMock(IUserRepository::class);

        // Первый вызов – актор найден, второй – цель не найдена
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

        $handler = new ChangeUserPostHandler($userRepo);
        $command = new ChangeUserPostCommand(
            userId: $targetId->value(),
            newPost: 'Manager',
            updatedBy: $actorId->value()
        );
        $handler->handle($command);
    }
}
