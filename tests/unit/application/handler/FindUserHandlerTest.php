<?php

namespace unit\application\handler;

use Codeception\Test\Unit;
use core\application\query\FindUserQuery;
use core\application\handler\FindUserHandler;
use core\application\port\IUserRepository;
use core\application\dto\UserFiltersDto;
use core\domain\entity\User;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\application\dto\CollectionDto;
use core\application\dto\UserDto;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;

class FindUserHandlerTest extends Unit
{
    private function createUser(?string $id = null, string $email = 'john@example.com'): User
    {
        $userId = $id ? new UserId($id) : UserId::generate();
        return new User(
            $userId,
            'Doe',
            'John',
            null,
            new Email($email),
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

    /**
     * @throws Exception
     */
    public function testHandleWithFiltersAndDefaultPagination(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $user = $this->createUser(null, 'john@example.com'); // ID генерируется автоматически

        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->with($this->callback(function (UserFiltersDto $filters) {
                return $filters->email === 'john@example.com'
                    && $filters->phone === null
                    && $filters->role === null
                    && $filters->post === null
                    && $filters->status === null
                    && $filters->limit === 20
                    && $filters->offset === 0
                    && $filters->orderBy === ['created_at' => SORT_DESC];
            }))
            ->willReturn(['items' => [$user], 'total' => 1]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(
            email: 'john@example.com',
            limit: 20,
            page: 1
        );
        $collection = $handler->handle($query);

        $this->assertInstanceOf(CollectionDto::class, $collection);
        $this->assertCount(1, $collection->items);
        $this->assertEquals(1, $collection->total);
        $this->assertEquals(1, $collection->page);
        $this->assertEquals(20, $collection->limit);

        // Проверяем, что items содержат UserDto
        $this->assertInstanceOf(UserDto::class, $collection->items[0]);
    }

    /**
     * @throws Exception
     */
    public function testHandleWithMultipleFiltersAndCustomPagination(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $user1 = $this->createUser(null, 'user1@example.com');
        $user2 = $this->createUser(null, 'user2@example.com');

        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->with($this->callback(function (UserFiltersDto $filters) {
                return $filters->email === 'user@example.com'
                    && $filters->role === 'admin'
                    && $filters->status === 1
                    && $filters->limit === 10
                    && $filters->offset === 10 // page=2, limit=10
                    && $filters->orderBy === ['surname' => SORT_ASC];
            }))
            ->willReturn(['items' => [$user1, $user2], 'total' => 25]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(
            email: 'user@example.com',
            role: 'admin',
            status: 1,
            limit: 10,
            page: 2,
            orderBy: ['surname' => SORT_ASC]
        );
        $collection = $handler->handle($query);

        $this->assertInstanceOf(CollectionDto::class, $collection);
        $this->assertCount(2, $collection->items);
        $this->assertEquals(25, $collection->total);
        $this->assertEquals(2, $collection->page);
        $this->assertEquals(10, $collection->limit);
    }

    /**
     * @throws Exception
     */
    public function testHandleWithDefaultPaginationWhenNotProvided(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->with($this->callback(function (UserFiltersDto $filters) {
                return $filters->limit === 20
                    && $filters->offset === 0
                    && $filters->orderBy === ['created_at' => SORT_DESC];
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(); // без параметров
        $collection = $handler->handle($query);

        $this->assertEquals(20, $collection->limit);
        $this->assertEquals(1, $collection->page);
        $this->assertEquals(0, $collection->total);
    }

    /**
     * @throws Exception
     */
    public function testHandleEmptyResult(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->willReturn(['items' => [], 'total' => 0]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(email: 'notfound@example.com');
        $collection = $handler->handle($query);

        $this->assertCount(0, $collection->items);
        $this->assertEquals(0, $collection->total);
        $this->assertEquals(1, $collection->page);
        $this->assertEquals(20, $collection->limit);
    }

    /**
     * @throws Exception
     */
    public function testHandleWithDateFilters(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->with($this->callback(function (UserFiltersDto $filters) {
                return $filters->createdFrom === '2024-01-01'
                    && $filters->createdTo === '2024-12-31'
                    && $filters->updatedFrom === '2024-06-01'
                    && $filters->updatedTo === '2024-06-30'
                    && $filters->lastLoginFrom === '2024-07-01'
                    && $filters->lastLoginTo === '2024-07-31';
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(
            createdFrom: '2024-01-01',
            createdTo: '2024-12-31',
            updatedFrom: '2024-06-01',
            updatedTo: '2024-06-30',
            lastLoginFrom: '2024-07-01',
            lastLoginTo: '2024-07-31'
        );
        $collection = $handler->handle($query);
        $this->assertInstanceOf(CollectionDto::class, $collection);
    }

    /**
     * @throws Exception
     */
    public function testHandleWithPostAndPhoneFilters(): void
    {
        $userRepo = $this->createMock(IUserRepository::class);
        $userRepo->expects($this->once())
            ->method('findWithFilters')
            ->with($this->callback(function (UserFiltersDto $filters) {
                return $filters->phone === '+1234567890'
                    && $filters->post === 'Developer';
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $handler = new FindUserHandler($userRepo);
        $query = new FindUserQuery(
            phone: '+1234567890',
            post: 'Developer'
        );
        $collection = $handler->handle($query);
        $this->assertInstanceOf(CollectionDto::class, $collection);
    }
}
