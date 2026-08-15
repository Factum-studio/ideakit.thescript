<?php

namespace unit\domain\valueObject;

use Codeception\Test\Unit;
use core\domain\valueObject\Role;
use InvalidArgumentException;

class RoleTest extends Unit
{
    public function testValidRoles()
    {
        $user = new Role('user');
        $this->assertEquals('user', $user->value());
        $admin = new Role('admin');
        $this->assertEquals('admin', $admin->value());
        $manager = new Role('manager');
        $this->assertEquals('manager', $manager->value());
    }

    public function testRoleIsCaseSensitive()
    {
        $this->expectException(InvalidArgumentException::class);
        new Role('User');
    }

    public function testIsAdmin()
    {
        $admin = new Role('admin');
        $user = new Role('user');
        $manager = new Role('manager');
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($manager->isAdmin());
    }

    public function testEquals()
    {
        $r1 = new Role('user');
        $r2 = new Role('user');
        $r3 = new Role('admin');
        $this->assertTrue($r1->equals($r2));
        $this->assertFalse($r1->equals($r3));
    }

    /**
     * @dataProvider invalidRoleProvider
     */
    public function testInvalidRolesThrowException(string $invalidRole)
    {
        $this->expectException(InvalidArgumentException::class);
        new Role($invalidRole);
    }

    public static function invalidRoleProvider(): array
    {
        return [
            ['superuser'],
            ['admin '],
            [' admin'],
            ['admin  '],
            ['User'],
            ['Admin'],
            ['MANAGER'],
            [''],
            ['   '],
            ['user,admin'],
            ['0'],
            ['null'],
        ];
    }
}
