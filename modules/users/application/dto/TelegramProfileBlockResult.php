<?php

declare(strict_types=1);

namespace modules\users\application\dto;

use DateTimeImmutable;
use modules\users\application\enum\TelegramProfileBlockOutcome;
use modules\users\domain\valueObject\TelegramBotStatus;

final class TelegramProfileBlockResult
{
    public function __construct(
        public readonly string $telegramIdentityProfileId,
        public readonly TelegramBotStatus $telegramBotStatus,
        public readonly string $correlationId,
        public readonly TelegramProfileBlockOutcome $outcome,
        public readonly ?DateTimeImmutable $blockedAt,
    ) {
    }
}
