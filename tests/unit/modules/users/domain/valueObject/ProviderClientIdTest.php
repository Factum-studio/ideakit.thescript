<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\valueObject;

use Codeception\Test\Unit;
use modules\users\domain\exception\InvalidProviderClientId;
use modules\users\domain\valueObject\ProviderClientId;

final class ProviderClientIdTest extends Unit
{
    public function testAcceptsValueAtMaximumLength(): void
    {
        $value = str_repeat('я', 255);
        $providerClientId = ProviderClientId::fromString($value);

        self::assertSame($value, $providerClientId->toString());
        self::assertTrue($providerClientId->equals(ProviderClientId::fromString($value)));
    }

    /**
     * @dataProvider invalidValues
     */
    public function testRejectsInvalidValue(string $value, string $reason): void
    {
        $this->expectException(InvalidProviderClientId::class);
        $this->expectExceptionMessage($reason);

        ProviderClientId::fromString($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => ['', 'empty'];
        yield 'whitespace only' => [" \t\u{00A0}", 'empty'];
        yield 'longer than 255 characters' => [str_repeat('я', 256), 'too_long'];
        yield 'invalid UTF-8' => [chr(0xC3) . chr(0x28), 'invalid_utf8'];
        yield 'line feed' => ["provider\nsubject", 'control_character'];
        yield 'null byte' => ["provider\0subject", 'control_character'];
    }
}
