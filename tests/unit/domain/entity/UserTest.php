<?php

namespace unit\domain\entity;

use Codeception\Test\Unit;
use core\domain\entity\User;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserId;
use core\domain\valueObject\Email;
use core\domain\valueObject\Phone;
use core\domain\valueObject\Role;
use core\domain\valueObject\UserStatus;
use core\domain\valueObject\UserIdentityId;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

class UserTest extends Unit
{
    private $userId;
    private $user;

    protected function _before(): void
    {
        $this->userId = UserId::generate();
        $this->user = new User(
            $this->userId,
            'Doe',
            'John',
            'Michael',
            new Email('john@example.com'),
            new Phone('+1234567890'),
            new Role('user'),
            'Developer',
            new UserStatus(1),
            'authKey123',
            new DateTimeImmutable('2023-01-01 00:00:00'),
            new DateTimeImmutable('2023-01-01 00:00:00'),
            null
        );
    }

    public function testGetters()
    {
        $this->assertEquals($this->userId, $this->user->getId());
        $this->assertEquals('Doe', $this->user->getSurname());
        $this->assertEquals('John', $this->user->getName());
        $this->assertEquals('Michael', $this->user->getPatronymic());
        $this->assertEquals('john@example.com', $this->user->getEmail()->value());
        $this->assertEquals('+1234567890', $this->user->getPhone()->value());
        $this->assertEquals('user', $this->user->getRole()->value());
        $this->assertEquals('Developer', $this->user->getPost());
        $this->assertEquals(1, $this->user->getStatus()->value());
        $this->assertEquals('authKey123', $this->user->getAuthKey());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->user->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $this->user->getUpdatedAt());
        $this->assertNull($this->user->getLastLoginAt());
    }

    public function testUpdateProfile()
    {
        $this->user->updateProfile(
            'Smith',
            'Jane',
            null,
            new Email('jane@example.com'),
            new Phone('+0987654321'),
            'Manager'
        );
        $this->assertEquals('Smith', $this->user->getSurname());
        $this->assertEquals('Jane', $this->user->getName());
        $this->assertNull($this->user->getPatronymic());
        $this->assertEquals('jane@example.com', $this->user->getEmail()->value());
        $this->assertEquals('+0987654321', $this->user->getPhone()->value());
        $this->assertEquals('Manager', $this->user->getPost());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testChangeRole()
    {
        $this->user->changeRole(new Role('admin'));
        $this->assertEquals('admin', $this->user->getRole()->value());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testChangeStatus()
    {
        $this->user->changeStatus(new UserStatus(0));
        $this->assertEquals(0, $this->user->getStatus()->value());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testUpdateLastLogin()
    {
        $this->user->updateLastLogin();
        $this->assertInstanceOf(DateTimeImmutable::class, $this->user->getLastLoginAt());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testAddIdentity()
    {
        $identityId = UserIdentityId::generate();
        $identity = new UserIdentity(
            $identityId,
            $this->userId,
            'google',
            'client123',
            new DateTimeImmutable()
        );
        $this->user->addIdentity($identity);
        $identities = $this->user->getIdentities();
        $this->assertCount(1, $identities);
        $this->assertSame($identity, $identities[0]);

        // Добавление дубликата не должно добавить второй
        $identityDup = new UserIdentity(
            UserIdentityId::generate(),
            $this->userId,
            'google',
            'client123',
            new DateTimeImmutable()
        );
        $this->user->addIdentity($identityDup);
        $this->assertCount(1, $this->user->getIdentities());
    }

    public function testChangeAuthKey()
    {
        $this->user->changeAuthKey('newAuthKey456');
        $this->assertEquals('newAuthKey456', $this->user->getAuthKey());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testCreateWithNullFields()
    {
        $user = new User(
            UserId::generate(),
            'Doe',
            'John',
            null,
            null,
            null,
            new Role('user'),
            null,
            new UserStatus(1),
            'authKey',
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            null
        );
        $this->assertNull($user->getPatronymic());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getPhone());
        $this->assertNull($user->getPost());
        $this->assertNull($user->getLastLoginAt());
    }

    public function testCreatesCommonUserWithoutProfileNames(): void
    {
        $user = new User(
            UserId::generate(),
            null,
            null,
            null,
            null,
            null,
            new Role(Role::ROLE_USER),
            null,
            new UserStatus(UserStatus::STATUS_ACTIVE),
            'authKey',
            new DateTimeImmutable('2026-09-05 10:00:00+00:00'),
            new DateTimeImmutable('2026-09-05 10:00:00+00:00'),
        );

        self::assertNull($user->getSurname());
        self::assertNull($user->getName());
    }

    public function testUpdateProfileSetsNullForEmailAndPhone()
    {
        $this->user->updateProfile(
            'Doe',
            'John',
            null,
            null,
            null,
            null
        );
        $this->assertNull($this->user->getEmail());
        $this->assertNull($this->user->getPhone());
        $this->assertNull($this->user->getPost());
        $this->assertNotEquals($this->user->getCreatedAt(), $this->user->getUpdatedAt());
    }

    public function testAddMultipleIdentities()
    {
        $identity1 = new UserIdentity(
            UserIdentityId::generate(),
            $this->userId,
            'google',
            'client123',
            new DateTimeImmutable()
        );
        $identity2 = new UserIdentity(
            UserIdentityId::generate(),
            $this->userId,
            'facebook',
            'client456',
            new DateTimeImmutable()
        );
        $this->user->addIdentity($identity1);
        $this->user->addIdentity($identity2);
        $this->assertCount(2, $this->user->getIdentities());
        $this->assertSame($identity1, $this->user->getIdentities()[0]);
        $this->assertSame($identity2, $this->user->getIdentities()[1]);
    }
}
