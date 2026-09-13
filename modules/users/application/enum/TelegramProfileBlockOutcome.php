<?php

declare(strict_types=1);

namespace modules\users\application\enum;

enum TelegramProfileBlockOutcome: string
{
    case BLOCKED = 'BLOCKED';
    case ALREADY_BLOCKED = 'ALREADY_BLOCKED';
    case STALE_IGNORED = 'STALE_IGNORED';
    case PROFILE_ANONYMIZED = 'PROFILE_ANONYMIZED';
}
