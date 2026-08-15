<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\UserId;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class UserIdTest extends Unit
{
    public function testValidUuid()
    {
        $uuid = Uuid::uuid4()->toString();
        $userId = new UserId($uuid);
        $this->assertEquals($uuid, $userId->value());
    }

    public function testValidUuidWithoutDashes()
    {
        $hex = str_replace('-', '', Uuid::uuid4()->toString());
        $userId = new UserId($hex);
        $this->assertEquals($hex, $userId->value());
    }

    public function testInvalidUuidThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);
        new UserId('not-a-uuid');
    }

    /**
     * @dataProvider invalidUuidProvider
     */
    public function testInvalidUuidThrowsExceptionWithData(string $invalid)
    {
        $this->expectException(InvalidArgumentException::class);
        new UserId($invalid);
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
        $userId = UserId::generate();
        $this->assertTrue(Uuid::isValid($userId->value()));
    }

    public function testGenerateUsesUuid7()
    {
        $userId = UserId::generate();
        $uuid = Uuid::fromString($userId->value());
        $this->assertEquals(7, $uuid->getVersion());
    }

    public function testEquals()
    {
        $uuid = Uuid::uuid4()->toString();
        $id1 = new UserId($uuid);
        $id2 = new UserId($uuid);
        $id3 = UserId::generate();
        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testEqualsIsCaseSensitive()
    {
        $lower = new UserId('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11');
        $upper = new UserId('A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11');
        $this->assertFalse($lower->equals($upper));
    }

    public function testToString()
    {
        $uuid = Uuid::uuid4()->toString();
        $userId = new UserId($uuid);
        $this->assertEquals($uuid, (string)$userId);
    }
}
