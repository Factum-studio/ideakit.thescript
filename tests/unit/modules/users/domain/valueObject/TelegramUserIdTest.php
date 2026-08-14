<?php

declare(strict_types=1);

namespace tests\unit\modules\users\domain\valueObject;

use Codeception\Test\Unit;
use modules\users\domain\exception\InvalidTelegramUserId;
use modules\users\domain\valueObject\TelegramUserId;

final class TelegramUserIdTest extends Unit
{
    /**
     * @dataProvider validValues
     */
    public function testAcceptsCanonicalBoundaryValues(string $value): void
    {
        $telegramUserId = TelegramUserId::fromString($value);

        self::assertSame($value, $telegramUserId->toString());
        self::assertTrue($telegramUserId->equals(TelegramUserId::fromString($value)));
        self::assertSame($value, $telegramUserId->toProviderClientId()->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validValues(): iterable
    {
        yield 'minimum' => ['1'];
        yield 'PostgreSQL bigint maximum' => ['9223372036854775807'];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testRejectsNonCanonicalOrOutOfRangeValues(string $value, string $reason): void
    {
        $this->expectException(InvalidTelegramUserId::class);
        $this->expectExceptionMessage($reason);

        TelegramUserId::fromString($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => ['', 'non_canonical'];
        yield 'zero' => ['0', 'non_canonical'];
        yield 'negative' => ['-1', 'non_canonical'];
        yield 'explicit plus sign' => ['+1', 'non_canonical'];
        yield 'leading zero' => ['01', 'non_canonical'];
        yield 'decimal notation' => ['1.0', 'non_canonical'];
        yield 'surrounding whitespace' => [' 1 ', 'non_canonical'];
        yield 'non-decimal characters' => ['12a', 'non_canonical'];
        yield 'above PostgreSQL bigint maximum' => ['9223372036854775808', 'out_of_range'];
        yield 'more than 19 digits' => ['100000000000000000000', 'out_of_range'];
    }
}
