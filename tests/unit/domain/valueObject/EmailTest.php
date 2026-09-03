<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\Email;
use InvalidArgumentException;

class EmailTest extends Unit
{
    public function testValidEmail()
    {
        $email = new Email('test@example.com');
        $this->assertEquals('test@example.com', $email->value());
    }

    public function testEquals()
    {
        $email1 = new Email('test@example.com');
        $email2 = new Email('test@example.com');
        $email3 = new Email('other@example.com');
        $this->assertTrue($email1->equals($email2));
        $this->assertFalse($email1->equals($email3));
    }

    public function testEqualsIsCaseSensitive()
    {
        $lower = new Email('test@example.com');
        $upper = new Email('TEST@EXAMPLE.COM');
        $this->assertFalse($lower->equals($upper));
    }

    /**
     * @dataProvider invalidEmailProvider
     */
    public function testInvalidEmailThrowsException(string $invalid)
    {
        $this->expectException(InvalidArgumentException::class);
        new Email($invalid);
    }

    public static function invalidEmailProvider(): array
    {
        return [
            ['invalid'],
            ['missing@'],
            ['@missing'],
            ['space in@local.com'],
            ['double@@at.com'],
            ['trailing.dot@example.com.'],
            [''],
            ['   '],
        ];
    }

    /**
     * @dataProvider validEmailProvider
     */
    public function testValidEmailVariations(string $valid)
    {
        $email = new Email($valid);
        $this->assertEquals($valid, $email->value());
    }

    public static function validEmailProvider(): array
    {
        return [
            ['user+tag@example.com'],
            ['user.name@sub.domain.co'],
            ['USER@EXAMPLE.COM'],
        ];
    }
}
