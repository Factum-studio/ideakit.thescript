<?php

declare(strict_types=1);

namespace modules\users\application\enum;

enum TelegramIdentityResolutionOutcome: string
{
    case CREATED = 'CREATED';
    case UPDATED = 'UPDATED';
    case PROFILE_CREATED = 'PROFILE_CREATED';
    case UNCHANGED = 'UNCHANGED';
    case USER_INACTIVE = 'USER_INACTIVE';
    case PROFILE_ANONYMIZED = 'PROFILE_ANONYMIZED';
    case STALE_IGNORED = 'STALE_IGNORED';
}
