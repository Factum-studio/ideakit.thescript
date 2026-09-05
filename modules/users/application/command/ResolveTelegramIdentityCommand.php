<?php

declare(strict_types=1);

namespace modules\users\application\command;

use DateTimeImmutable;
use modules\users\application\exception\InvalidTelegramIdentityCommandException;
use modules\users\domain\exception\InvalidTelegramProfileSnapshotException;
use modules\users\domain\exception\InvalidTelegramUserIdException;
use modules\users\domain\valueObject\TelegramProfileSnapshot;
use modules\users\domain\valueObject\TelegramUserId;

final class ResolveTelegramIdentityCommand
{
    public readonly TelegramUserId $telegramUserId;
    public readonly TelegramProfileSnapshot $profileSnapshot;

    public function __construct(
        string $telegramUserId,
        ?string $username,
        ?string $firstName,
        ?string $lastName,
        ?string $languageCode,
        public readonly DateTimeImmutable $observedAt,
        public readonly string $correlationId,
    ) {
        try {
            $this->telegramUserId = TelegramUserId::fromString($telegramUserId);
        } catch (InvalidTelegramUserIdException $exception) {
            throw new InvalidTelegramIdentityCommandException(
                'invalid_telegram_user_id',
                0,
                $exception,
            );
        }

        try {
            $this->profileSnapshot = TelegramProfileSnapshot::create(
                $username,
                $firstName,
                $lastName,
                $languageCode,
            );
        } catch (InvalidTelegramProfileSnapshotException $exception) {
            throw new InvalidTelegramIdentityCommandException(
                'invalid_telegram_profile_snapshot',
                0,
                $exception,
            );
        }

        if ($observedAt->getTimezone()->getName() !== 'UTC') {
            throw new InvalidTelegramIdentityCommandException('observed_at_must_be_utc');
        }

        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di',
            $correlationId,
        ) !== 1) {
            throw new InvalidTelegramIdentityCommandException('invalid_correlation_id');
        }
    }
}
