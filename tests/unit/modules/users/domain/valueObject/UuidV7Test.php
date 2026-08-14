<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\valueObject;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\users\domain\valueObject\TelegramIdentityProfileId;
use modules\users\domain\valueObject\UserId;
use modules\users\domain\valueObject\UserIdentityId;
use modules\users\domain\valueObject\UuidV7;

final class UuidV7Test extends Unit
{
    private const UUID = '01890f4d-3c2a-7f48-8c0b-123456789abc';

    public function testAcceptsUuidV7AndNormalizesItToLowercase(): void
    {
        $uuid = UuidV7::fromString(strtoupper(self::UUID));

        self::assertSame(self::UUID, $uuid->toString());
        self::assertTrue($uuid->equals(UuidV7::fromString(self::UUID)));
    }

    /**
     * @param class-string<UserId|UserIdentityId|TelegramIdentityProfileId> $identifierClass
     * @dataProvider typedIdentifierClasses
     */
    public function testTypedIdentifiersCompareEqualValues(string $identifierClass): void
    {
        $identifier = $identifierClass::fromString(strtoupper(self::UUID));

        self::assertSame(self::UUID, $identifier->toString());
        self::assertTrue($identifier->equals($identifierClass::fromString(self::UUID)));
    }

    /**
     * @return iterable<string, array{class-string<UserId|UserIdentityId|TelegramIdentityProfileId>}>
     */
    public static function typedIdentifierClasses(): iterable
    {
        yield 'user id' => [UserId::class];
        yield 'user identity id' => [UserIdentityId::class];
        yield 'Telegram identity profile id' => [TelegramIdentityProfileId::class];
    }

    /**
     * @dataProvider invalidUuidValues
     */
    public function testRejectsInvalidUuidV7(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_uuid_v7');

        UuidV7::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUuidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['not-a-uuid'];
        yield 'other version' => ['01890f4d-3c2a-6f48-8c0b-123456789abc'];
        yield 'invalid variant' => ['01890f4d-3c2a-7f48-7c0b-123456789abc'];
    }
}
