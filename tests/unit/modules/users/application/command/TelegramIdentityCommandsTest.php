<?php

declare(strict_types=1);

namespace tests\unit\modules\users\application\command;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use modules\users\application\command\ResolveTelegramIdentityCommand;
use modules\users\application\exception\InvalidTelegramIdentityCommandException;

final class TelegramIdentityCommandsTest extends Unit
{
    private const CORRELATION_ID = '01890f4d-3c2a-7f48-8c0b-123456789ac0';

    public function testBuildsTypedInputWithNullableProfileFields(): void
    {
        $observedAt = self::utc('2026-09-05 12:00:00');

        $command = new ResolveTelegramIdentityCommand(
            '1000000000000000001',
            null,
            null,
            null,
            null,
            $observedAt,
            self::CORRELATION_ID,
        );

        self::assertSame('1000000000000000001', $command->telegramUserId->value());
        self::assertTrue($command->profileSnapshot->isEmpty());
        self::assertSame($observedAt, $command->observedAt);
        self::assertSame(self::CORRELATION_ID, $command->correlationId);
    }

    public function testRejectsInvalidTelegramUserIdWithoutExposingInput(): void
    {
        $invalidTelegramUserId = 'invalid-telegram-user-id';

        try {
            new ResolveTelegramIdentityCommand(
                $invalidTelegramUserId,
                null,
                null,
                null,
                null,
                self::utc('2026-09-05 12:00:00'),
                self::CORRELATION_ID,
            );
            self::fail('Expected invalid Telegram user ID to be rejected.');
        } catch (InvalidTelegramIdentityCommandException $exception) {
            self::assertSame('invalid_telegram_user_id', $exception->getMessage());
            self::assertStringNotContainsString($invalidTelegramUserId, $exception->getMessage());
        }
    }

    public function testRejectsNonUtcObservedAt(): void
    {
        $this->expectException(InvalidTelegramIdentityCommandException::class);
        $this->expectExceptionMessage('observed_at_must_be_utc');

        new ResolveTelegramIdentityCommand(
            '1000000000000000001',
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-09-05 17:00:00', new DateTimeZone('Asia/Yekaterinburg')),
            self::CORRELATION_ID,
        );
    }

    public function testRejectsInvalidCorrelationIdWithoutExposingInput(): void
    {
        $invalidCorrelationId = 'invalid-correlation-id';

        try {
            new ResolveTelegramIdentityCommand(
                '1000000000000000001',
                null,
                null,
                null,
                null,
                self::utc('2026-09-05 12:00:00'),
                $invalidCorrelationId,
            );
            self::fail('Expected invalid correlation ID to be rejected.');
        } catch (InvalidTelegramIdentityCommandException $exception) {
            self::assertSame('invalid_correlation_id', $exception->getMessage());
            self::assertStringNotContainsString($invalidCorrelationId, $exception->getMessage());
        }
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
