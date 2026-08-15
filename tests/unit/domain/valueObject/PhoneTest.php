<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\Phone;
use InvalidArgumentException;

class PhoneTest extends Unit
{
    public function testValidPhone()
    {
        $phone = new Phone('+1234567890');
        $this->assertEquals('+1234567890', $phone->value());
        $phone2 = new Phone('1234567890');
        $this->assertEquals('1234567890', $phone2->value());
    }

    public function testEquals()
    {
        $p1 = new Phone('+1234567890');
        $p2 = new Phone('+1234567890');
        $p3 = new Phone('+0987654321');
        $this->assertTrue($p1->equals($p2));
        $this->assertFalse($p1->equals($p3));
    }

    public function testEqualsIsStrictAboutPlus()
    {
        $withPlus = new Phone('+71234567890');
        $withoutPlus = new Phone('71234567890');
        $this->assertFalse($withPlus->equals($withoutPlus));
    }

    /**
     * @dataProvider invalidPhoneProvider
     */
    public function testInvalidPhoneThrowsException(string $invalid)
    {
        $this->expectException(InvalidArgumentException::class);
        new Phone($invalid);
    }

    public static function invalidPhoneProvider(): array
    {
        return [
            ['123456789'],        // 9 цифр
            ['1234567890123456'], // 16 цифр
            ['+123456789'],       // 9 цифр после +
            ['+1234567890123456'],// 16 цифр после +
            ['123-456-7890'],
            ['(123) 456-7890'],
            ['123 456 7890'],
            ['abc1234567890'],
            ['+12a34567890'],
            ['++1234567890'],
            ['1234567890a'],
            ['1234567890 '],
            [' 1234567890'],
            [''],
            ['   '],
        ];
    }

    /**
     * @dataProvider validPhoneProvider
     */
    public function testValidPhoneVariations(string $valid)
    {
        $phone = new Phone($valid);
        $this->assertEquals($valid, $phone->value());
    }

    public static function validPhoneProvider(): array
    {
        return [
            ['1234567890'],
            ['+1234567890'],
            ['123456789012345'],
            ['+123456789012345'],
            ['0000000000'],
            ['+0000000000'],
        ];
    }
}
