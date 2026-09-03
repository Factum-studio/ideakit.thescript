<?php

namespace unit\domain\entity;

use Codeception\Test\Unit;
use core\domain\entity\UserIdentity;
use core\domain\valueObject\UserIdentityId;
use core\domain\valueObject\UserId;
use DateTimeImmutable;

class UserIdentityTest extends Unit
{
    public function testGetters()
    {
        $id = UserIdentityId::generate();
        $userId = UserId::generate();
        $createdAt = new DateTimeImmutable();
        $identity = new UserIdentity($id, $userId, 'telegram', '123456', $createdAt);

        $this->assertEquals($id, $identity->getId());
        $this->assertEquals($userId, $identity->getUserId());
        $this->assertEquals('telegram', $identity->getProvider());
        $this->assertEquals('123456', $identity->getProviderClientId());
        $this->assertEquals($createdAt, $identity->getCreatedAt());
    }

    public function testGettersReturnCorrectTypes()
    {
        $id = UserIdentityId::generate();
        $userId = UserId::generate();
        $createdAt = new DateTimeImmutable();
        $identity = new UserIdentity($id, $userId, 'telegram', '123456', $createdAt);

        $this->assertInstanceOf(UserIdentityId::class, $identity->getId());
        $this->assertInstanceOf(UserId::class, $identity->getUserId());
        $this->assertIsString($identity->getProvider());
        $this->assertIsString($identity->getProviderClientId());
        $this->assertInstanceOf(DateTimeImmutable::class, $identity->getCreatedAt());
    }

    public function testCreateWithDifferentProvidersAndClientIds()
    {
        $identity1 = new UserIdentity(
            UserIdentityId::generate(),
            UserId::generate(),
            'google',
            'client_123',
            new DateTimeImmutable()
        );
        $identity2 = new UserIdentity(
            UserIdentityId::generate(),
            UserId::generate(),
            'facebook',
            'fb_456',
            new DateTimeImmutable()
        );
        $this->assertEquals('google', $identity1->getProvider());
        $this->assertEquals('client_123', $identity1->getProviderClientId());
        $this->assertEquals('facebook', $identity2->getProvider());
        $this->assertEquals('fb_456', $identity2->getProviderClientId());
    }
}
