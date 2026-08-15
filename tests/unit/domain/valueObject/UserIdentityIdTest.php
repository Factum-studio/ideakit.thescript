<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\UserIdentityId;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class UserIdentityIdTest extends Unit
{
    public function testValidUuid()
    {
        $uuid = Uuid::uuid4()->toString();
        $userId = new UserIdentityId($uuid);
        $this->assertEquals($uuid, $userId->value());
    }

    public function testValidUuidWithoutDashes()
    {
        $hex = str_replace('-', '', Uuid::uuid4()->toString());
        $userId = new UserIdentityId($hex);
        $this->assertEquals($hex, $userId->value());
    }

    public function testInvalidUuidThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new UserIdentityId('not-a-uuid');
    }

    /**
     * @dataProvider invalidUuidProvider
     */
    public function testInvalidUuidThrowsExceptionWithData(string $invalid)
    {
        $this->expectException(InvalidArgumentException::class);
        new UserIdentityId($invalid);
    }

    public static function invalidUuidProvider(): array
    {
        return [
            [''],
            ['   '],
            ['not-a-uuid'],
            ['12345'],
            ['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a1'],
            ['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11x'],
        ];
    }

    public function testGenerate()
    {
        $userId = UserIdentityId::generate();
        $this->assertTrue(Uuid::isValid($userId->value()));
    }

    public function testGenerateUsesUuid7()
    {
        $userId = UserIdentityId::generate();
        $uuid = Uuid::fromString($userId->value());
        $this->assertEquals(7, $uuid->getVersion());
    }

    public function testEquals()
    {
        $uuid = Uuid::uuid4()->toString();
        $id1 = new UserIdentityId($uuid);
        $id2 = new UserIdentityId($uuid);
        $id3 = UserIdentityId::generate();
        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testEqualsIsCaseSensitive()
    {
        $lower = new UserIdentityId('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');
        $upper = new UserIdentityId('A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11');
        $this->assertFalse($lower->equals($upper));
    }

    public function testToString()
    {
        $uuid = Uuid::uuid4()->toString();
        $userId = new UserIdentityId($uuid);
        $this->assertEquals($uuid, (string)$userId);
    }
}
