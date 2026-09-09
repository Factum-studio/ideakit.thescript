<?php

declare(strict_types=1);

namespace modules\users\application\dto;

use modules\users\application\enum\TelegramIdentityResolutionOutcome;
use modules\users\application\enum\UserAccountStatus;
use modules\users\domain\valueObject\TelegramBotStatus;

final class ResolvedTelegramIdentity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $userIdentityId,
        public readonly ?string $telegramIdentityProfileId,
        public readonly UserAccountStatus $userStatus,
        public readonly ?TelegramBotStatus $telegramBotStatus,
        public readonly string $correlationId,
        public readonly TelegramIdentityResolutionOutcome $outcome,
    ) {
    }
}
