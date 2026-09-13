<?php

declare(strict_types=1);

namespace modules\users\application\command;

use DateTimeImmutable;
use InvalidArgumentException;
use modules\users\application\enum\TelegramProfileBlockReason;
use modules\users\application\exception\InvalidTelegramIdentityCommandException;
use modules\users\domain\valueObject\TelegramIdentityProfileId;

final class MarkTelegramProfileBlockedCommand
{
    public readonly TelegramIdentityProfileId $telegramIdentityProfileId;
    public readonly TelegramProfileBlockReason $reason;

    public function __construct(
        string $telegramIdentityProfileId,
        string $reason,
        public readonly DateTimeImmutable $blockedAt,
        public readonly string $correlationId,
    ) {
        try {
            $this->telegramIdentityProfileId = new TelegramIdentityProfileId(
                $telegramIdentityProfileId,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidTelegramIdentityCommandException(
                'invalid_telegram_identity_profile_id',
                0,
                $exception,
            );
        }

        $typedReason = TelegramProfileBlockReason::tryFrom($reason);

        if ($typedReason === null) {
            throw new InvalidTelegramIdentityCommandException(
                'invalid_telegram_profile_block_reason',
            );
        }

        $this->reason = $typedReason;

        if ($blockedAt->getTimezone()->getName() !== 'UTC') {
            throw new InvalidTelegramIdentityCommandException('blocked_at_must_be_utc');
        }

        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di',
            $correlationId,
        ) !== 1) {
            throw new InvalidTelegramIdentityCommandException('invalid_correlation_id');
        }
    }
}
