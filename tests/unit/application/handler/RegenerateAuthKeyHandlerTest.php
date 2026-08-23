<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\command\RegenerateAuthKeyCommand;
use core\application\handler\RegenerateAuthKeyHandler;
use core\application\port\IUserRepository;
use core\application\port\ISecurityService;
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
use RuntimeException;

class RegenerateAuthKeyHandlerTest extends Unit
{
    private function createUser(string $id, string $authKey = 'oldAuthKey'): User
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
            $authKey,
            new DateTimeImmutable('2023-01-01 00:00:00'),
            new DateTimeImmutable('2023-01-01 00:00:00'),
            null
        );
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function testHandleSuccess(): void
    {
        $userId = UserId::generate();
        $user = $this->createUser($userId->value(), 'oldAuthKey');

        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($userId)
            ->willReturn($user);

        $security->expects($this->once())
            ->method('generateRandomString')
            ->with(32)
            ->willReturn('newAuthKey');

        $userRepo->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($user));

        $handler = new RegenerateAuthKeyHandler($userRepo, $security);
        $command = new RegenerateAuthKeyCommand($userId->value());
        $handler->handle($command);

        // Проверяем, что ключ действительно изменился
        $this->assertEquals('newAuthKey', $user->getAuthKey());
        // Проверяем, что updatedAt обновился (можно проверить, что отличается от исходного)
        $this->assertNotEquals(new DateTimeImmutable('2023-01-01 00:00:00'), $user->getUpdatedAt());
    }

    /**
     * @throws Exception
     */
    public function testHandleUserNotFoundThrowsException(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $security = $this->createMock(ISecurityService::class);

        $nonExistingId = Uuid::uuid4()->toString();

        $userRepo->expects($this->once())
            ->method('findById')
            ->with($this->callback(fn($id) => $id->value() === $nonExistingId))
            ->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage("User with ID {$nonExistingId} not found");

        $handler = new RegenerateAuthKeyHandler($userRepo, $security);
        $command = new RegenerateAuthKeyCommand($nonExistingId);
        $handler->handle($command);
    }
}
