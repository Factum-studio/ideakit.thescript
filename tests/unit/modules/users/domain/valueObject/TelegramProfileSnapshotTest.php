<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\valueObject;

use Codeception\Test\Unit;
use modules\users\domain\exception\InvalidTelegramProfileSnapshotException;
use modules\users\domain\valueObject\TelegramProfileSnapshot;

final class TelegramProfileSnapshotTest extends Unit
{
    public function testNormalizesEmptyFieldsAndPreservesUnicode(): void
    {
        $snapshot = TelegramProfileSnapshot::create('', 'Анна', '', 'ru');

        self::assertNull($snapshot->username());
        self::assertSame('Анна', $snapshot->firstName());
        self::assertNull($snapshot->lastName());
        self::assertSame('ru', $snapshot->languageCode());
        self::assertFalse($snapshot->isEmpty());
        self::assertTrue($snapshot->equals(TelegramProfileSnapshot::create(null, 'Анна', null, 'ru')));
    }

    public function testAcceptsValuesAtLengthBoundaries(): void
    {
        $snapshot = TelegramProfileSnapshot::create(
            str_repeat('я', 64),
            str_repeat('я', 255),
            str_repeat('я', 255),
            str_repeat('я', 16),
        );

        self::assertSame(64, mb_strlen((string) $snapshot->username(), 'UTF-8'));
        self::assertSame(255, mb_strlen((string) $snapshot->firstName(), 'UTF-8'));
        self::assertSame(255, mb_strlen((string) $snapshot->lastName(), 'UTF-8'));
        self::assertSame(16, mb_strlen((string) $snapshot->languageCode(), 'UTF-8'));
    }

    public function testCreatesEmptySnapshot(): void
    {
        $snapshot = TelegramProfileSnapshot::empty();

        self::assertTrue($snapshot->isEmpty());
        self::assertNull($snapshot->username());
        self::assertNull($snapshot->firstName());
        self::assertNull($snapshot->lastName());
        self::assertNull($snapshot->languageCode());
    }

    /**
     * @dataProvider invalidFields
     */
    public function testRejectsInvalidFields(
        ?string $username,
        ?string $firstName,
        ?string $lastName,
        ?string $languageCode,
        string $reason,
    ): void {
        $this->expectException(InvalidTelegramProfileSnapshotException::class);
        $this->expectExceptionMessage($reason);

        TelegramProfileSnapshot::create($username, $firstName, $lastName, $languageCode);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?string, ?string, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'username too long' => [str_repeat('я', 65), null, null, null, 'username_too_long'];
        yield 'first name too long' => [null, str_repeat('я', 256), null, null, 'first_name_too_long'];
        yield 'last name too long' => [null, null, str_repeat('я', 256), null, 'last_name_too_long'];
        yield 'language code too long' => [null, null, null, str_repeat('я', 17), 'language_code_too_long'];
        yield 'username invalid UTF-8' => ["\xFF", null, null, null, 'username_invalid_utf8'];
        yield 'first name invalid UTF-8' => [null, "\xFF", null, null, 'first_name_invalid_utf8'];
        yield 'last name invalid UTF-8' => [null, null, "\xFF", null, 'last_name_invalid_utf8'];
        yield 'language code invalid UTF-8' => [null, null, null, "\xFF", 'language_code_invalid_utf8'];
        yield 'username control character' => ["user\nname", null, null, null, 'username_control_character'];
        yield 'first name control character' => [null, "first\0name", null, null, 'first_name_control_character'];
        yield 'last name control character' => [null, null, "last\tname", null, 'last_name_control_character'];
        yield 'language code control character' => [null, null, null, "r\ru", 'language_code_control_character'];
    }
}
