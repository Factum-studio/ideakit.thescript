<?php

declare(strict_types=1);

namespace modules\users\application\dto;

use InvalidArgumentException;
use modules\users\domain\entity\TelegramIdentityProfile;

final class VersionedTelegramIdentityProfile
{
    public function __construct(
        private readonly TelegramIdentityProfile $profile,
        private readonly int $lockVersion,
    ) {
        if ($lockVersion < 0) {
            throw new InvalidArgumentException('negative_telegram_profile_lock_version');
        }
    }

    public function profile(): TelegramIdentityProfile
    {
        return $this->profile;
    }

    public function lockVersion(): int
    {
        return $this->lockVersion;
    }
}
