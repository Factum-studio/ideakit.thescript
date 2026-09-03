<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\valueObject;

use Codeception\Test\Unit;
use InvalidArgumentException;
use modules\users\domain\valueObject\TelegramIdentityProfileId;

final class TelegramIdentityProfileIdTest extends Unit
{
    private const UUID = '01890f4d-3c2a-7f48-8c0b-123456789abc';

    public function testAcceptsUuidV7AndNormalizesItToLowercase(): void
    {
        $id = new TelegramIdentityProfileId(strtoupper(self::UUID));

        self::assertSame(self::UUID, $id->value());
        self::assertTrue($id->equals(new TelegramIdentityProfileId(self::UUID)));
        self::assertFalse($id->equals(new TelegramIdentityProfileId('01890f4d-3c2a-7f48-8c0b-123456789abd')));
    }

    /**
     * @dataProvider invalidUuidValues
     */
    public function testRejectsInvalidUuidV7(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_telegram_identity_profile_id');

        new TelegramIdentityProfileId($value);
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
        yield 'trailing newline' => [self::UUID . "\n"];
    }
}
