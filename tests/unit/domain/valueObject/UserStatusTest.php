<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\UserStatus;
use InvalidArgumentException;
use TypeError;

class UserStatusTest extends Unit
{
    public function testValidStatuses()
    {
        $active = new UserStatus(1);
        $this->assertEquals(1, $active->value());
        $inactive = new UserStatus(0);
        $this->assertEquals(0, $inactive->value());
    }

    /**
     * @dataProvider invalidStatusProvider
     */
    public function testInvalidStatusThrowsException(int $invalid)
    {
        $this->expectException(InvalidArgumentException::class);
        new UserStatus($invalid);
    }

    public static function invalidStatusProvider(): array
    {
        return [
            [2],
            [3],
            [-1],
            [100],
            [999],
        ];
    }

    public function testConstructorAcceptsOnlyInt()
    {
        // В PHP 8+ с type-hint int передача строки вызовет TypeError
        $this->expectException(TypeError::class);
        new UserStatus('1');
    }

    public function testIsActive()
    {
        $active = new UserStatus(1);
        $inactive = new UserStatus(0);
        $this->assertTrue($active->isActive());
        $this->assertFalse($inactive->isActive());
    }

    public function testEquals()
    {
        $s1 = new UserStatus(1);
        $s2 = new UserStatus(1);
        $s3 = new UserStatus(0);
        $this->assertTrue($s1->equals($s2));
        $this->assertFalse($s1->equals($s3));
    }

    public function testEqualsWithSameValue()
    {
        $s1 = new UserStatus(1);
        $s2 = new UserStatus(1);
        $this->assertTrue($s1->equals($s2));
    }

    public function testInvalidStatusThrowsExceptionWithMessage()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user status');
        new UserStatus(2);
    }
}
